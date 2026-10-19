<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/View.php';

$data = __DIR__ . '/../../data';
$log = new Log($data . '/app.log');
Response::emit(View::clients($_SERVER, $data . '/token', require __DIR__ . '/../../config/clients.php', $log));
