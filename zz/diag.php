<?php
error_reporting(E_ALL);
ini_set('display_errors','1');
$root = dirname(dirname(dirname(dirname(__DIR__))));
require_once $root . '/inc/init.php';
header('Content-Type: text/plain; charset=utf-8');
global $PARSER_MODES, $plugin_controller;
$out = [];
$out['plugin_list_syntax'] = plugin_list('syntax');
$inst = p_get_instructions("~~conceptgraph full~~\n", true, 'xhtml');
$out['instructions'] = $inst;
$out['substition'] = $PARSER_MODES['substition'] ?? null;
$out['load_named'] = plugin_load('syntax', 'conceptgraph_conceptgraph') ? 'ok' : 'NULL';
$out['load_plain'] = plugin_load('syntax', 'conceptgraph') ? 'ok' : 'NULL';
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
