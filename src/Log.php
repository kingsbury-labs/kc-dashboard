<?php

class Log
{
    private string $path;
    /** @var string[] */
    private array $secrets = [];

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function addSecret(string $value): void
    {
        if ($value === '') {
            return;
        }
        $this->secrets[$value] = $value;
        // Longest first, so a secret that contains another is replaced whole.
        uksort($this->secrets, fn($a, $b) => strlen($b) <=> strlen($a));
    }

    public function redact(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $text = str_replace($secret,'[redacted]', $text);
        }
        return $text;
    }

    public function write(string $level, string $message, array $context = []): void
    {
        $line = gmdate('c') . ' ' . strtoupper($level) . ' ' . $this->redact($message);
        if ($context) {
            $line .= ' ' . $this->redact(json_encode($this->scrub($context), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
        // One line per entry, so a multi-line value cannot forge a second entry.
        $line = str_replace(["\r", "\n"], ['\\r', '\\n'], $line);
        file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function exception(Throwable $e, array $context = [], bool $withTrace = false): void
    {
        $context['exception'] = [
            'class' => get_class($e),
            'message' => $e->getMessage(),
        ];
        if ($withTrace) {
            $context['exception']['trace'] = $e->getTraceAsString();
        }
        $this->write('error', 'exception', $context);
    }

    private function scrub($value)
    {
        if (is_string($value)) {
            return $this->redact($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[is_string($k) ? $this->redact($k) : $k] = $this->scrub($v);
            }
            return $out;
        }
        if ($value instanceof Throwable) {
            return $this->scrub([
                'class' => get_class($value),
                'message' => $value->getMessage(),
                'trace' => $value->getTraceAsString(),
            ]);
        }
        if (is_object($value)) {
            return $this->scrub(get_object_vars($value));
        }
        return $value;
    }
}
