<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/Cockpit/CockpitPage.php';

$data = __DIR__ . '/../data';
Response::emit(CockpitPage::handle($_SERVER, $data . '/token', new Log($data . '/app.log')));
