<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Memory/MemoryStore.php';

$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
Response::emit(MemoryApi::remember($_SERVER, (string) file_get_contents('php://input', false, null, 0, 65536), $data . '/token',
    function () use ($data): array {
        $pdo = Db::open($data . '/dashboard.sqlite');
        Db::migrate($pdo);
        return [$pdo, Vault::fromConfig()];
    }, $log));
