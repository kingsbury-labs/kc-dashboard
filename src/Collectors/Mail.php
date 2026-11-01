<?php
require_once __DIR__ . '/Collector.php';
require_once __DIR__ . '/../Mail/ImapReader.php';
require_once __DIR__ . '/../Mail/Summarizer.php';
require_once __DIR__ . '/../Mail/Relevance.php';
require_once __DIR__ . '/../Graph/Classifier.php';

/**
 * The newest inbox messages, each summed up in one line by the local model, saved to a cache file the cockpit reads.
 * Records no facts. Reads at most every 15 minutes. A summary is kept by UID and never asked for again; mail text is
 * never stored, only sender, subject, date, Gmail link, summary and reply-needed. The app password is read from its
 * file at run time and registered with the log for redaction before it is sent anywhere.
 * A failure writes its reason into the cache (a refused sign-in as REFUSED, nothing of the server's text) beside
 * the last rows, then throws for the collector run.
 */
class Mail extends Collector
{
    public const EVERY = 900;
    public const ROWS = 10;
    public const CHARS = 2000;
    public const DAYS = 14;
    public const MAX_BYTES = 262144;
    public const REFUSED = "Gmail sign-in refused: check the app password's account";

    private Closure $wire;
    private string $user;
    private string $passwordFile;
    private Closure $model;
    private string $cache;
    private Log $log;

    private ?Closure $tag;

    // $wire: fn(): ImapWire. $model: fn(array message): ?array{summary:string, reply:bool}.
    // $tag: fn(array message): ?string project (Relevance::tag); null builds it from the running config.
    public function __construct(Closure $wire, string $user, string $passwordFile, Closure $model, string $cache, Log $log, ?Closure $tag = null)
    {
        $this->tag = $tag;
        $this->wire = $wire;
        $this->user = $user;
        $this->passwordFile = $passwordFile;
        $this->model = $model;
        $this->cache = $cache;
        $this->log = $log;
    }

    public function name(): string
    {
        return 'mail';
    }

    public function collect(int $now): array
    {
        $old = self::cached($this->cache, $now);
        if ($old !== null && $old['error'] === null && $old['age'] < self::EVERY) {
            return [];
        }
        $keep = [];
        foreach ((array) ($old['rows'] ?? []) as $r) {
            // A row from before tagging has no project key and is read again.
            if (is_array($r) && is_int($r['uid'] ?? null) && array_key_exists('project', $r) && ($r['summary'] !== null || $r['project'] === null)) {
                $keep[$r['uid']] = $r;
            }
        }
        try {
            $pass = trim((string) @file_get_contents($this->passwordFile));
            if ($this->user === '') {
                throw new RuntimeException('Gmail account is not set');
            }
            if ($pass === '') {
                throw new RuntimeException('Gmail app password file is missing or empty');
            }
            $this->log->addSecret($pass);
            $imap = new ImapReader(($this->wire)());
            try {
                $imap->login($this->user, $pass);
                $imap->examine();
                $uids = array_slice($imap->search($now - self::DAYS * 86400), -self::ROWS);
                $need = array_values(array_diff($uids, array_keys($keep)));
                $got = $need ? $imap->fetch($need, self::CHARS) : [];
            } finally {
                $imap->logout();
            }
        } catch (Throwable $e) {
            $this->save(['at' => $now, 'error' => $e instanceof ImapRefused ? self::REFUSED : $this->log->redact($e->getMessage()),
                'rows' => array_values($old['rows'] ?? [])]);
            throw $e;
        }
        $tag = $this->tag ?? $this->defaultTag();
        $rows = [];
        foreach (array_reverse($uids) as $uid) {
            if (isset($keep[$uid])) {
                $rows[] = $keep[$uid];
                continue;
            }
            $m = $got[$uid] ?? null;
            if ($m === null) {
                continue;
            }
            // A tag is a project name, or the Classifier's {project, reason, unsure}.
            try {
                $t = $tag($m);
            } catch (Throwable $e) {
                $t = null;
            }
            $project = is_array($t) ? ($t['project'] ?? null) : $t;
            $reason = is_array($t) ? (string) ($t['reason'] ?? '') : '';
            $unsure = is_array($t) && ($t['unsure'] ?? false) === true;
            // Hidden mail is not summed up.
            try {
                $s = $project === null ? null : ($this->model)($m + ['uid' => $uid]);
            } catch (Throwable $e) {
                $s = null;
            }
            $rows[] = ['uid' => $uid, 'from' => $m['from'], 'addr' => (string) ($m['addr'] ?? ''), 'project' => $project,
                'reason' => $reason, 'unsure' => $unsure, 'subject' => $m['subject'], 'date' => $m['date'],
                'link' => $m['message_id'] === '' ? 'https://mail.google.com/mail/u/0/#inbox'
                    : 'https://mail.google.com/mail/u/0/#search/' . rawurlencode('rfc822msgid:' . $m['message_id']),
                'summary' => $s['summary'] ?? null, 'reply' => ($s['reply'] ?? false) === true];
        }
        $this->save(['at' => $now, 'error' => null, 'rows' => $rows]);
        return [];
    }

    // The saved rows with their age in seconds, or null when there are none.
    public static function cached(string $path, int $now): ?array
    {
        $data = is_file($path) ? json_decode((string) @file_get_contents($path, false, null, 0, self::MAX_BYTES), true) : null;
        if (!is_array($data) || !is_int($data['at'] ?? null) || !is_array($data['rows'] ?? null)) {
            return null;
        }
        return ['at' => $data['at'], 'age' => max(0, $now - $data['at']), 'error' => is_string($data['error'] ?? null) ? $data['error'] : null,
            'rows' => $data['rows']];
    }

    // The knowledge graph in data/dashboard.sqlite (the Graph collector builds it), corrections beside the cache in
    // data/mail-senders.json, the model from config/collectors.php.
    private function defaultTag(): Closure
    {
        $cfg = (require __DIR__ . '/../../config/collectors.php')['mail'];
        $c = new Classifier(new GraphStore(Db::open(dirname($this->cache) . '/dashboard.sqlite')), dirname($this->cache) . '/' . $cfg['senders'],
            Summarizer::generate($cfg['ollama'], $cfg['model']));
        return fn(array $m): array => $c->tag($m);
    }

    private function save(array $data): void
    {
        $tmp = $this->cache . '.tmp';
        if (file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) === false || !rename($tmp, $this->cache)) {
            throw new RuntimeException('mail cache could not be written');
        }
    }
}
