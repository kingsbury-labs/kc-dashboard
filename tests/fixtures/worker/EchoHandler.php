<?php

class EchoHandler
{
    public function handle(array $payload): string
    {
        return 'echo:' . ($payload['word'] ?? '');
    }
}

class ThrowHandler
{
    public function handle(array $payload): string
    {
        throw new RuntimeException('handler blew up');
    }
}
