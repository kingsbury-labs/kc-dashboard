<?php

// One line at a time to and from an IMAP server. TlsWire is the real one; tests script a fake.
interface ImapWire
{
    public function send(string $line): void;

    // The next line with its CRLF, or null when the server has gone.
    public function line(): ?string;

    public function bytes(int $n): string;

    public function close(): void;
}

// Gmail over TLS on 993. The CA bundle is PHP's own openssl setting, else the one curl uses.
final class TlsWire implements ImapWire
{
    /** @var resource */
    private $s;

    public function __construct(string $host = 'imap.gmail.com', int $port = 993, int $timeout = 20)
    {
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        $ca = (string) ini_get('openssl.cafile') ?: (string) ini_get('curl.cainfo');
        if ($ca !== '') {
            $ssl['cafile'] = $ca;
        }
        $s = @stream_socket_client("tls://$host:$port", $errno, $err, $timeout, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));
        if ($s === false) {
            throw new RuntimeException("IMAP connect failed: $err");
        }
        stream_set_timeout($s, $timeout);
        $this->s = $s;
    }

    public function send(string $line): void
    {
        for ($done = 0; $done < strlen($line); $done += $n) {
            $n = fwrite($this->s, substr($line, $done));
            if ($n === false || $n === 0) {
                throw new RuntimeException('IMAP write failed');
            }
        }
    }

    public function line(): ?string
    {
        $l = fgets($this->s, 65536);
        return $l === false ? null : $l;
    }

    public function bytes(int $n): string
    {
        $out = '';
        while (strlen($out) < $n) {
            $b = fread($this->s, $n - strlen($out));
            if ($b === false || $b === '') {
                throw new RuntimeException('IMAP read ended inside a literal');
            }
            $out .= $b;
        }
        return $out;
    }

    public function close(): void
    {
        @fclose($this->s);
    }
}

class ImapRefused extends RuntimeException
{
}

/**
 * Gmail's inbox, read only (XAMPP's PHP has no imap extension). The mailbox is opened with EXAMINE and bodies are
 * fetched with BODY.PEEK, so nothing is ever marked read. Every command is checked against ALLOWED before it is
 * sent: LOGIN, EXAMINE INBOX, UID SEARCH SINCE, UID FETCH of ENVELOPE and a peeked first part, LOGOUT. Nothing else.
 */
class ImapReader
{
    private const Q = '"(?:[^"\\\\\r\n]|\\\\["\\\\])*"';
    public const ALLOWED = '/\A(?:LOGIN ' . self::Q . ' ' . self::Q . '|EXAMINE INBOX|LOGOUT|UID SEARCH SINCE \d{1,2}-[A-Z][a-z]{2}-\d{4}'
        . '|UID FETCH \d+(?:,\d+)* \(UID ENVELOPE BODY\.PEEK\[1\]<0\.\d{1,5}>\))\z/';
    public const MAX_LITERAL = 1048576;

    private ImapWire $wire;
    private int $tag = 0;

    public function __construct(ImapWire $wire)
    {
        $this->wire = $wire;
        $hello = $wire->line();
        if ($hello === null || !str_starts_with($hello, '* OK')) {
            throw new RuntimeException('IMAP server did not greet');
        }
    }

    public static function allowed(string $cmd): bool
    {
        return preg_match(self::ALLOWED, $cmd) === 1;
    }

    public function login(string $user, string $pass): void
    {
        [$ok, $text] = $this->run('LOGIN ' . self::quote($user) . ' ' . self::quote($pass));
        if (!$ok) {
            throw new ImapRefused('Gmail sign-in refused: ' . $text);
        }
    }

    public function examine(): void
    {
        [$ok, $text] = $this->run('EXAMINE INBOX');
        if (!$ok) {
            throw new RuntimeException('EXAMINE INBOX failed: ' . $text);
        }
    }

    /** @return int[] UIDs of messages since the day of $since, lowest first */
    public function search(int $since): array
    {
        [$ok, $text, $lines] = $this->run('UID SEARCH SINCE ' . gmdate('j-M-Y', $since));
        if (!$ok) {
            throw new RuntimeException('UID SEARCH failed: ' . $text);
        }
        $uids = [];
        foreach ($lines as $l) {
            if (preg_match('/^\* SEARCH((?: \d+)*)/', $l, $m)) {
                $uids = array_merge($uids, array_map('intval', preg_split('/ /', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY)));
            }
        }
        sort($uids);
        return $uids;
    }

    /**
     * Envelope and the first $chars bytes of part 1, per UID: from, subject, date (unix or null), message_id, text.
     * @param int[] $uids
     */
    public function fetch(array $uids, int $chars): array
    {
        [$ok, $text, $lines] = $this->run('UID FETCH ' . implode(',', array_map('intval', $uids)) . ' (UID ENVELOPE BODY.PEEK[1]<0.' . $chars . '>)');
        if (!$ok) {
            throw new RuntimeException('UID FETCH failed: ' . $text);
        }
        $out = [];
        foreach ($lines as $l) {
            if (!preg_match('/^\* \d+ FETCH \(/', $l)) {
                continue;
            }
            $i = strpos($l, '(');
            $items = self::parse($l, $i);
            $f = [];
            for ($k = 0; $k + 1 < count($items); $k += 2) {
                $f[strtoupper(preg_replace('/<.*$/', '', (string) $items[$k]))] = $items[$k + 1];
            }
            $env = $f['ENVELOPE'] ?? null;
            if (!isset($f['UID']) || !is_array($env)) {
                continue;
            }
            $from = $env[2][0] ?? null;
            $who = is_array($from) ? (self::header((string) ($from[0] ?? '')) ?: ($from[2] ?? '') . '@' . ($from[3] ?? '')) : '';
            $date = is_string($env[0] ?? null) ? strtotime($env[0]) : false;
            $out[(int) $f['UID']] = [
                'from' => $who,
                'addr' => is_array($from) ? strtolower(($from[2] ?? '') . '@' . ($from[3] ?? '')) : '',
                'subject' => self::header((string) ($env[1] ?? '')),
                'date' => $date === false ? null : $date,
                'message_id' => trim((string) ($env[9] ?? ''), '<> '),
                'text' => self::plain(is_string($f['BODY[1]'] ?? null) ? $f['BODY[1]'] : '', $chars),
            ];
        }
        return $out;
    }

    // Says goodbye and closes; a server that has already gone is not an error here.
    public function logout(): void
    {
        try {
            $this->run('LOGOUT');
        } catch (Throwable $e) {
        } finally {
            $this->wire->close();
        }
    }

    // Sends one allowed command and reads to its tagged answer: [ok, the answer's text, the untagged responses].
    private function run(string $cmd): array
    {
        if (!self::allowed($cmd)) {
            throw new LogicException('IMAP command refused: not on the read-only list');
        }
        $tag = 'k' . (++$this->tag);
        $this->wire->send($tag . ' ' . $cmd . "\r\n");
        $untagged = [];
        while (($r = $this->response()) !== null) {
            if (str_starts_with($r, $tag . ' ')) {
                $rest = substr($r, strlen($tag) + 1);
                return [str_starts_with($rest, 'OK'), $rest, $untagged];
            }
            $untagged[] = $r;
        }
        throw new RuntimeException('IMAP connection closed');
    }

    // One whole response: a line, and for each {n} literal its bytes and the line that goes on after them.
    private function response(): ?string
    {
        $out = '';
        while (($line = $this->wire->line()) !== null) {
            $out .= $line;
            if (!preg_match('/\{(\d+)\}\r\n\z/', $line, $m)) {
                return rtrim($out, "\r\n");
            }
            if ((int) $m[1] > self::MAX_LITERAL) {
                throw new RuntimeException('IMAP literal too large');
            }
            $out .= $this->wire->bytes((int) $m[1]);
        }
        return $out === '' ? null : $out;
    }

    /** A parenthesised list from $s at $i (on its "("): strings, null for NIL, atoms (BODY[...] kept whole), lists. */
    public static function parse(string $s, int &$i): array
    {
        $i++;
        $out = [];
        $n = strlen($s);
        while ($i < $n) {
            $c = $s[$i];
            if ($c === ' ' || $c === "\r" || $c === "\n") {
                $i++;
            } elseif ($c === ')') {
                $i++;
                return $out;
            } elseif ($c === '(') {
                $out[] = self::parse($s, $i);
            } elseif ($c === '"') {
                $v = '';
                for ($i++; $i < $n && $s[$i] !== '"'; $i++) {
                    if ($s[$i] === '\\') {
                        $i++;
                    }
                    $v .= $s[$i] ?? '';
                }
                $i++;
                $out[] = $v;
            } elseif ($c === '{' && preg_match('/\G\{(\d+)\}\r\n/', $s, $m, 0, $i)) {
                $i += strlen($m[0]);
                $out[] = substr($s, $i, (int) $m[1]);
                $i += (int) $m[1];
            } else {
                $start = $i;
                for ($depth = 0; $i < $n; $i++) {
                    $c = $s[$i];
                    if ($c === '[') {
                        $depth++;
                    } elseif ($c === ']') {
                        $depth--;
                    } elseif ($depth === 0 && ($c === ' ' || $c === '(' || $c === ')')) {
                        break;
                    }
                }
                $a = substr($s, $start, $i - $start);
                $out[] = $a === 'NIL' ? null : $a;
            }
        }
        return $out;
    }

    private static function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    private static function header(string $s): string
    {
        $d = function_exists('iconv_mime_decode') ? @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') : false;
        return trim(is_string($d) ? $d : $s);
    }

    // Part 1 as plain words: base64 or quoted-printable undone by its look (no BODYSTRUCTURE is fetched), tags out.
    private static function plain(string $s, int $chars): string
    {
        $squeezed = preg_replace('/\s+/', '', $s);
        if (strlen($squeezed) >= 40 && preg_match('~^[A-Za-z0-9+/]+=*$~', $squeezed)) {
            $s = (string) base64_decode($squeezed);
        } elseif (preg_match('/=\r?\n|=[0-9A-F]{2}/', $s)) {
            $s = quoted_printable_decode($s);
        }
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', mb_convert_encoding($s, 'UTF-8', 'UTF-8'))), 0, $chars);
    }
}
