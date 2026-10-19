<?php
require_once __DIR__ . '/../../src/Net/HttpClient.php';

// Records the urls asked for and returns scripted responses by url; starts no connection.
class FakeHttpClient implements HttpClient
{
    /** @var string[] */
    public array $calls = [];
    /** @var array<string,array{status:int,body:string}> */
    private array $responses = [];

    public function willReturn(string $url, string $body, int $status = 200): void
    {
        $this->responses[$url] = ['status' => $status, 'body' => $body];
    }

    public function get(string $url): array
    {
        $this->calls[] = $url;
        return $this->responses[$url] ?? throw new RuntimeException('no scripted response for ' . $url);
    }
}
