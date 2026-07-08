<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/BiddingService.php';
require_once __DIR__ . '/HandService.php';
require_once __DIR__ . '/Bot.php';
require_once __DIR__ . '/rikken_engine.php';
require_once __DIR__ . '/DeclaringService.php';
require_once __DIR__ . '/PlayService.php';
require_once __DIR__ . '/ScoringService.php';
require_once __DIR__ . '/GameService.php';   // for touch()

final class GameRunner {
    /**
     * Run consecutive BOT turns until it's a human's turn or the phase pauses.
     * Called after every human action AND by the cron watchdog.
     */
    public static function advance(int $gameId): void {
        GameService::touch($gameId);   // heartbeat: records activity for the watchdog

        for ($guard = 0; $guard < 400; $guard++) {
            $g = DB::conn()->prepare('SELECT status, current_seat, current_hand_id FROM games WHERE id = ?');
            $g->execute([$gameId]);
            $game = $g->fetch();
            if (!$game) return;

            $status = $game['status'];

            if ($status === 'hand_scoring') {
                ScoringService::scoreHand($gameId);
                return;
            }

            if (!in_array($status, ['bidding','declaring','playing'], true)) return;

            $seat = (int) $game['current_seat'];

            $t = DB::conn()->prepare('SELECT seat_type FROM game_seats WHERE game_id=? AND seat=?');
            $t->execute([$gameId, $seat]);
            if ($t->fetchColumn() === 'human') return;

            $handId = (int) $game['current_hand_id'];
            switch ($status) {
                case 'bidding':
                    $cards = ids_to_cards(HandService::seatHand($handId, $seat));
                    $rank  = BiddingService::state($handId)['highestRank'];
                    BiddingService::applyBid($gameId, $seat, Bot::chooseBid($cards, $rank, $seat));
                    break;
                case 'declaring':
                    [$tr, $cs] = Bot::chooseDeclaration($handId, $seat);
                    DeclaringService::applyDeclaration($gameId, $seat, $tr, $cs);
                    break;
                case 'playing':
                    PlayService::playCard($gameId, $seat, Bot::chooseCard($gameId, $handId, $seat));
                    break;
            }
        }
    }
}