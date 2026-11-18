<?php

// Minimal local Stripe stand-in for harness tests: logs each call, answers like Stripe would.
file_put_contents(__DIR__.'/calls.log', $_SERVER['REQUEST_METHOD'].' '.$_SERVER['REQUEST_URI']."\n", FILE_APPEND);
header('Content-Type: application/json');
$id = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
echo json_encode(['id' => $id, 'object' => 'subscription', 'status' => 'canceled']);
