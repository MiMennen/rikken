<?php
declare(strict_types=1);

// --- production hygiene: never leak internals to the client ---
ini_set('display_errors', '0');     // don't print PHP errors into the response
ini_set('log_errors', '1');         // do log them server-side
error_reporting(E_ALL);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

require_once __DIR__ . '/GameService.php';

/** Turn any uncaught fatal into a clean JSON 500 instead of an HTML error page. */
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) http_response_code(500);
        echo json_encode(['error' => 'server error']);
    }
});

/**
 * Resolve the caller's identity from a token ONLY. No dev fallback.
 * @return array{game:int, seat:int}
 */
function requireAuth(array $in): array {
    $token = $in['token'] ?? null;
    if (!is_string($token) || $token === '') {
        http_response_code(401);
        echo json_encode(['error' => 'missing token']);
        exit;
    }
    $res = GameService::resolveToken($token);
    if (!$res) {
        http_response_code(403);
        echo json_encode(['error' => 'invalid token']);
        exit;
    }
    return $res;   // ['game'=>int, 'seat'=>int]
}