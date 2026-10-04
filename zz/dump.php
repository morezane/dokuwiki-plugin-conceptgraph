<?php
error_reporting(E_ALL);
ini_set('display_errors','1');
$root = dirname(dirname(dirname(dirname(__DIR__))));
require_once $root . '/inc/init.php';
header('Content-Type: text/plain; charset=utf-8');
global $conf;
$out = [];
$out['useslash'] = $conf['useslash'];
$out['deaccent'] = $conf['deaccent'];
$out['baseurl'] = DOKU_BASE;
echo json_encode($out, JSON_PRETTY_PRINT);
