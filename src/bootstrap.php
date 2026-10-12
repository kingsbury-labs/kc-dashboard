<?php
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Http/Response.php';

ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('zend.exception_ignore_args', '1');

set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new ErrorException($msg, 0, $no, $file, $line);
});

set_exception_handler(function (Throwable $e): void {
    Response::emit(Response::error($e));
});
