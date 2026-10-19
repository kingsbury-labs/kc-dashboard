<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Http/Token.php';
require_once __DIR__ . '/../../src/Running/NowService.php';

// The wall's live feed, and the only endpoint that streams: `event: now` every 5 seconds.
// EventSource cannot send X-KC-Token, so the page first POSTs here with the token (checkWrite) and gets a ticket:
// 32 random bytes, good for one GET within 30 seconds, sent as ?ticket=. Only its hash is stored, it is spent
// before the stream starts, and the app log redacts it. Apache's combined access log does record the query string,
// ticket included; the line is written when the request ends, after the ticket is spent. A vhost CustomLog
// without the query string would keep it out of that log too. A cookie would, but any page on another
// localhost port sends it.

const WALL_TICKET_TTL = 30;
const WALL_TICKET_MAX = 16;
const WALL_EVERY = 5;
// XAMPP's max_execution_time is 120 and PHP on Windows counts wall-clock time, so the stream ends itself first.
// KC_WALL_SECONDS can only shorten it (the test sets 2).
const WALL_RUN = 55;

// The ticket file cannot be opened or locked: the request is refused with a 503, never served unlocked.
class WallTicketsUnavailable extends RuntimeException
{
}

// The ticket list under an exclusive lock: expired entries are dropped before $fn sees it, and its changes are saved.
function wall_tickets(string $file, Closure $fn)
{
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        throw new WallTicketsUnavailable('ticket file not writable');
    }
    try {
        if (!@flock($fh, LOCK_EX)) {
            throw new WallTicketsUnavailable('ticket file not locked');
        }
        $list = json_decode((string) stream_get_contents($fh), true);
        $now = time();
        $list = array_filter(is_array($list) ? $list : [], fn($exp) => is_int($exp) && $exp > $now);
        $result = $fn($list);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($list));
        return $result;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function wall_mint(string $file): string
{
    $ticket = bin2hex(random_bytes(32));
    wall_tickets($file, function (array &$list) use ($ticket): void {
        $list[hash('sha256', $ticket)] = time() + WALL_TICKET_TTL;
        if (count($list) > WALL_TICKET_MAX) {
            asort($list);
            $list = array_slice($list, -WALL_TICKET_MAX, null, true);
        }
    });
    return $ticket;
}

// True once per minted ticket: the entry is removed as it is checked.
function wall_take(string $file, string $ticket): bool
{
    $hash = hash('sha256', $ticket);
    return wall_tickets($file, function (array &$list) use ($hash): bool {
        if (!isset($list[$hash])) {
            return false;
        }
        unset($list[$hash]);
        return true;
    });
}

$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
$tickets = $data . '/wall-tickets.json';
$forbidden = Response::json(403, ['error' => 'forbidden']);
try {
    if (!(new RequestGuard(''))->checkRead($_SERVER)) {
        Response::emit($forbidden);
        exit;
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? null;
    if ($method === 'POST') {
        Response::emit((new RequestGuard(Token::load($data . '/token', $log)))->checkWrite($_SERVER)
            ? Response::json(200, ['ticket' => wall_mint($tickets)]) : $forbidden);
        exit;
    }
    $ticket = $_GET['ticket'] ?? null;
    if ($method !== 'GET' || !is_string($ticket) || !preg_match('/^[0-9a-f]{64}\z/', $ticket)) {
        Response::emit($forbidden);
        exit;
    }
    $log->addSecret($ticket);
    // The ticket stands in for the header EventSource cannot send. It is spent only once the address, the Host and
    // a same-origin, non-navigation fetch are checked.
    if (!(new RequestGuard(''))->checkFetchSite($_SERVER) || !wall_take($tickets, $ticket)) {
        Response::emit($forbidden);
        exit;
    }
} catch (WallTicketsUnavailable $e) {
    $log->exception($e, ['where' => 'wall-stream']);
    Response::emit(Response::json(503, ['error' => 'unavailable']));
    exit;
} catch (Throwable $e) {
    $log->exception($e, ['where' => 'wall-stream']);
    Response::emit(Response::error($e));
    exit;
}

$seconds = (int) getenv('KC_WALL_SECONDS');
$run = $seconds >= 1 && $seconds < WALL_RUN ? $seconds : WALL_RUN;
http_response_code(200);
foreach (['Content-Type' => 'text/event-stream', 'X-Accel-Buffering' => 'no'] + Response::SECURITY_HEADERS as $name => $value) {
    header($name . ': ' . $value);
}
while (ob_get_level() > 0) {
    ob_end_flush();
}
echo "retry: 3000\n\n";
flush();

$end = microtime(true) + $run;
$next = microtime(true);
$service = null;
while (!connection_aborted()) {
    try {
        $service ??= NowService::fromConfig($data, $log);
        $now = $service->now();
    } catch (Throwable $e) {
        $log->exception($e, ['where' => 'wall-stream']);
        $now = ['sessions' => [], 'loops' => [], 'tasks' => [], 'ports' => [], 'errors' => ['now']];
    }
    echo "event: now\ndata: " . json_encode($now, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    $next += WALL_EVERY;
    $wait = min($next, $end) - microtime(true);
    if ($wait > 0) {
        usleep((int) ($wait * 1000000));
    }
    if (microtime(true) >= $end) {
        break;
    }
}
