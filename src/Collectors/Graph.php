<?php
require_once __DIR__ . '/Collector.php';
require_once __DIR__ . '/../Graph/GraphStore.php';
require_once __DIR__ . '/../Graph/Readers.php';
require_once __DIR__ . '/../Mail/ImapReader.php';
require_once __DIR__ . '/../Mail/Relevance.php';

/**
 * Rebuilds the project knowledge graph each collector run, before Mail. Project folders, client records, kc-care's
 * list and Rob's corrections are re-read and replace their own edges. Sent mail is read incrementally: envelopes of
 * the last 90 days over the read-only IMAP client, only UIDs past the last one seen. Records no facts.
 * $src: 'projects' => name => folder, 'vendors' => name => domains, 'clients' => folder, 'care' => clients.json,
 * 'senders' => mail-senders.json, 'traps' => traps.md, 'asks' => asks.md. $wire null skips sent mail.
 */
class Graph extends Collector
{
    public const SENT_DAYS = 90;

    private GraphStore $g;
    private array $src;
    private ?Closure $wire;
    private string $user;
    private string $passwordFile;
    private Log $log;

    public function __construct(GraphStore $g, array $src, ?Closure $wire, string $user, string $passwordFile, Log $log)
    {
        $this->g = $g;
        $this->src = $src;
        $this->wire = $wire;
        $this->user = $user;
        $this->passwordFile = $passwordFile;
        $this->log = $log;
    }

    public function name(): string
    {
        return 'graph';
    }

    public function collect(int $now): array
    {
        $projects = (array) ($this->src['projects'] ?? []);
        $names = array_map('strval', array_keys($projects));
        $this->g->replace('projects', GraphReaders::projects($projects, (array) ($this->src['vendors'] ?? [])));
        $this->g->replace('clients', GraphReaders::clients((string) ($this->src['clients'] ?? ''), $names));
        $this->g->replace('care', GraphReaders::care((string) ($this->src['care'] ?? ''), $names));
        $this->g->replace('corrections', GraphReaders::corrections(Relevance::senders((string) ($this->src['senders'] ?? ''))));
        // Lessons (card 134), each source replaced alone: Rob's trap and ask files, landed cards' result.md files,
        // pending dream proposals. Read-only.
        $this->g->replace('traps', GraphReaders::traps(GraphReaders::read((string) ($this->src['traps'] ?? '')), $projects));
        $this->g->replace('fixes', GraphReaders::fixes(GraphReaders::cards($projects), $projects));
        $this->g->replace('asks', GraphReaders::asks(GraphReaders::read((string) ($this->src['asks'] ?? '')), $projects));
        $this->g->replace('dreams', GraphReaders::dreams(GraphReaders::proposals($projects), $projects));
        if ($this->wire !== null) {
            foreach (GraphReaders::sent($this->sent($now), $names) as $e) {
                $this->g->add('sent', $e);
            }
        }
        return [];
    }

    // Sent envelopes past the last UID seen; the app password is registered for redaction before it is sent.
    private function sent(int $now): array
    {
        $pass = trim((string) @file_get_contents($this->passwordFile));
        if ($this->user === '' || $pass === '') {
            return [];
        }
        $this->log->addSecret($pass);
        $last = (int) ($this->g->state('sent_uid') ?? 0);
        $imap = new ImapReader(($this->wire)());
        try {
            $imap->login($this->user, $pass);
            $imap->examine('SENT');
            $uids = array_values(array_filter($imap->search($now - self::SENT_DAYS * 86400), fn($u) => $u > $last));
            $got = $uids ? $imap->envelopes($uids) : [];
        } finally {
            $imap->logout();
        }
        if ($uids) {
            $this->g->setState('sent_uid', (string) max($uids));
        }
        return array_values($got);
    }

    // The sources from the running config: project folders, client records, kc-care, corrections, vendors.
    public static function sources(string $dataDir): array
    {
        $all = require __DIR__ . '/../../config/collectors.php';
        $cfg = $all['mail'];
        $projects = require __DIR__ . '/../../config/projects.php';
        $clients = require __DIR__ . '/../../config/clients.php';
        $p = [];
        foreach (array_merge(glob(rtrim($projects['root'], '\\/') . '/*', GLOB_ONLYDIR) ?: [], array_column($projects['extra'] ?? [], 'path')) as $dir) {
            $p[basename($dir)] = $dir;
        }
        return ['projects' => $p, 'vendors' => (array) ($cfg['vendors'] ?? []), 'clients' => (string) $clients['dir'],
            'care' => (string) ($cfg['care_clients'] ?? ''), 'senders' => $dataDir . '/' . $cfg['senders'],
            'traps' => (string) ($all['lessons']['traps'] ?? ''), 'asks' => (string) ($all['lessons']['asks'] ?? '')];
    }
}
