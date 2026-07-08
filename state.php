<?php
declare(strict_types=1);
require_once __DIR__ . '/web_bootstrap.php';
require_once __DIR__ . '/ViewState.php';

try {
    $auth = requireAuth($_GET);
    echo json_encode(ViewState::build($auth['game'], $auth['seat']), JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => 'bad request']);
}