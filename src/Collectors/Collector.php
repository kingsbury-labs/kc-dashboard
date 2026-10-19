<?php
require_once __DIR__ . '/../Log.php';
require_once __DIR__ . '/../Attention/FactStore.php';

// A rule collector: reads one source and returns facts ready for FactStore::record (with 'source' set). A failure
// throws; run() records it on the source and goes on to the next collector.
abstract class Collector
{
    abstract public function name(): string;

    /** @return array<int,array<string,mixed>> */
    abstract public function collect(int $now): array;

    // When the source last had good data. Finance sets this from its file's generated_at, not from the run.
    public function lastOkAt(int $now): int
    {
        return $now;
    }

    /**
     * Runs each collector alone: one that throws records its error and the rest still run.
     * @param Collector[] $collectors
     * @return array<string,int|string> per source name, the number of facts recorded or the error
     */
    public static function run(array $collectors, FactStore $store, Log $log, int $now): array
    {
        $out = [];
        foreach ($collectors as $c) {
            $name = $c->name();
            try {
                $facts = $c->collect($now);
                foreach ($facts as $fact) {
                    $store->record($fact, $now);
                }
                $store->sourceOk($name, $c->lastOkAt($now));
                $out[$name] = count($facts);
            } catch (Throwable $e) {
                $store->sourceFailed($name, $e->getMessage(), $log);
                $log->exception($e, ['where' => 'collector', 'source' => $name]);
                $out[$name] = $log->redact($e->getMessage());
            }
        }
        return $out;
    }

    // Days from $now's day to a YYYY-MM-DD date, rounded since a day across a daylight-saving change is 23 or 25 hours.
    protected static function daysUntil(string $date, int $now): int
    {
        return (int) round((strtotime($date . ' 00:00:00') - strtotime('today', $now)) / 86400);
    }
}
