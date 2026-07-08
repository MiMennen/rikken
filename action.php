<?php
declare(strict_types=1);
require_once __DIR__ . '/web_bootstrap.php';
require_once __DIR__ . '/GameRunner.php';
require_once __DIR__ . '/BiddingService.php';
require_once __DIR__ . '/DeclaringService.php';
require_once __DIR__ . '/PlayService.php';
require_once __DIR__ . '/ViewState.php';

try {
    $in   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $auth = requireAuth($in);
    $gameId = $auth['game'];
    $seat   = $auth['seat'];
    $action = (string)($in['action'] ?? '');

    // defensive: the token's seat must be a human seat for play actions
    if (in_array($action, ['bid','declare','play'], true)) {
        $t = DB::conn()->prepare('SELECT seat_type FROM game_seats WHERE game_id=? AND seat=?');
        $t->execute([$gameId, $seat]);
        if ($t->fetchColumn() !== 'human') throw new RuntimeException('not a human seat');
    }

    switch ($action) {
        case 'bid':
            BiddingService::applyBid($gameId, $seat, (string)$in['bid']);
            break;
        case 'declare':
            DeclaringService::applyDeclaration(
                $gameId, $seat,
                isset($in['trump'])  ? (int)$in['trump']  : null,
                isset($in['called']) ? (int)$in['called'] : null);
            break;
        case 'play':
            PlayService::playCard(
                $gameId, $seat, (int)$in['card'],
                !empty($in['faceDown']),
                isset($in['announced']) ? (int)$in['announced'] : null);
            break;
        case 'next':
            GameService::nextHand($gameId);
            break;
        default:
            throw new InvalidArgumentException('unknown action');
    }

    GameRunner::advance($gameId);
    echo json_encode(ViewState::build($gameId, $seat), JSON_THROW_ON_ERROR);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => 'bad request']);
}