<?php

/**
 * One line about a message and whether it wants a reply, from the local Ollama only: the address must be loopback,
 * so mail text never leaves the PC. The model sees sender, subject and the first 2,000 characters of part 1.
 * A model that is down or answers out of shape gives null, and the panel shows sender and subject alone.
 */
class Summarizer
{
    public static function prompt(array $m): string
    {
        return "Summarise this email in one plain sentence of at most 20 words, and say whether it needs a reply from Rob.\n"
            . "Answer only JSON: {\"summary\": \"...\", \"reply\": true or false}.\n\n"
            . 'From: ' . $m['from'] . "\nSubject: " . $m['subject'] . "\n\n" . mb_substr((string) $m['text'], 0, 2000);
    }

    // {"summary": string, "reply": bool} out of the model's answer, or null.
    public static function read(string $answer): ?array
    {
        $d = json_decode(trim($answer), true);
        if (!is_array($d) && preg_match('/\{.*\}/s', $answer, $m)) {
            $d = json_decode($m[0], true);
        }
        if (!is_array($d) || !is_string($d['summary'] ?? null) || trim($d['summary']) === '') {
            return null;
        }
        $line = trim((string) preg_replace('/\s+/u', ' ', $d['summary']));
        return ['summary' => mb_substr($line, 0, 200), 'reply' => ($d['reply'] ?? false) === true];
    }

    public static function ollama(string $base, string $model, int $timeout = 90): Closure
    {
        if (!preg_match('~^http://(127\.0\.0\.1|localhost)(:\d+)?/?$~', $base)) {
            throw new InvalidArgumentException('the summariser must be a loopback address');
        }
        return function (array $m) use ($base, $model, $timeout): ?array {
            $ch = curl_init(rtrim($base, '/') . '/api/generate');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'prompt' => self::prompt($m), 'stream' => false,
                    'format' => 'json', 'think' => false], JSON_INVALID_UTF8_SUBSTITUTE),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $d = is_string($body) && $code === 200 ? json_decode($body, true) : null;
            return is_string($d['response'] ?? null) ? self::read($d['response']) : null;
        };
    }
}
