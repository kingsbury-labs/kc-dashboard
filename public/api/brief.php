<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Briefing/Composer.php';

$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
Response::emit(BriefApi::post($_SERVER, $data . '/token', function () use ($data): array {
    $pdo = Db::open($data . '/dashboard.sqlite');
    Db::migrate($pdo);
    return [$pdo, Vault::fromConfig()];
}, $log));
