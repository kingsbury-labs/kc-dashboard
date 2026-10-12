<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/View.php';

$data = __DIR__ . '/../data';
Response::emit(View::page($_SERVER, $data . '/token', new Log($data . '/app.log')));
