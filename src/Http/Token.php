<?php

// The per-install token: created once, never overwritten, registered for redaction.
class Token
{
    public static function load(string $path, Log $log): string
    {
        $fh = @fopen($path, 'x');
        if ($fh !== false) {
            $value = bin2hex(random_bytes(32));
            fwrite($fh, $value);
            fclose($fh);
        } else {
            $value = trim((string) @file_get_contents($path));
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $value)) {
            throw new RuntimeException('token file unreadable or malformed: ' . $path);
        }
        $log->addSecret($value);
        return $value;
    }
}
