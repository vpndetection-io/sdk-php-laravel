<?php

declare(strict_types=1);

// A stand-in for the API, run under `php -S`: it records the path and the
// `Authorization` header of every request it gets and serves one answer to all.

$dir = (string) getenv('FAKE_API_DIR');
$headers = array_change_key_case(getallheaders(), CASE_LOWER);
file_put_contents($dir . '/requests.jsonl', json_encode([
    'path' => parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH),
    'authorization' => $headers['authorization'] ?? null,
]) . "\n", FILE_APPEND);

header('Content-Type: application/json');
echo file_get_contents($dir . '/answer.json');
