<?php
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__ . '/../GameService.php';
require_once __DIR__ . '/../GameRunner.php';

try {
    $in   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $name = trim((string)($in['name'] ?? 'You'));
    if ($name === '') $name = 'You';
    $name = mb_substr($name, 0, 40);

    $gid = GameService::create([
        ['type'=>'human','name'=>$name],
        ['type'=>'bot','name'=>'Oost'],
        ['type'=>'bot','name'=>'Zuid'],
        ['type'=>'bot','name'=>'West'],
    ]);
    GameService::start($gid);
    GameRunner::advance($gid);            // bots act up to the human's first turn

    $token = GameService::seatToken($gid, 0);
    echo json_encode(['url'=>"index.html?token=$token"], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error'=>$e->getMessage()]);
}