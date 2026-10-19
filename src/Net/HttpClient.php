<?php

// What a collector needs from the network: one GET. A failure (refused url, timeout, oversize body) throws.
interface HttpClient
{
    /** @return array{status:int, body:string} */
    public function get(string $url): array;
}
