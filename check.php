<?php
$t0 = microtime(true);
session_start();
$t1 = microtime(true);
require_once __DIR__ . '/config/db.php';
$t2 = microtime(true);
$pdo->query('SELECT 1')->fetchColumn();
$t3 = microtime(true);

header('Content-Type: application/json');
echo json_encode([
    'opcache'      => extension_loaded('Zend OPcache') ? 'ON' : 'OFF',
    'gd'           => extension_loaded('gd') ? 'ON' : 'OFF',
    'session_ms'   => round(($t1 - $t0) * 1000, 1),
    'db_connect_ms'=> round(($t2 - $t1) * 1000, 1),
    'db_query_ms'  => round(($t3 - $t2) * 1000, 1),
    'total_ms'     => round(($t3 - $t0) * 1000, 1),
]);
