<?php
require_once __DIR__ . '/EchoHandler.php';

return [
    'test.echo' => EchoHandler::class,
    'test.throw' => ThrowHandler::class,
];
