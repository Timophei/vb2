<?php
// Постоянный адрес меморандума: /memo/ = /doc/?memo
$_GET = ['memo' => ''] + $_GET;
require __DIR__ . '/../doc/index.php';
