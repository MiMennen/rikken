<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/DeckRepository.php';
require_once __DIR__ . '/rikken_engine.php';

final class HandService {

    /**
     * Deal one hand for $gameId using the persistent deck.
     * - reconstructs the 4-5-4 deal from the saved stack
     * - writes hands + 52 hand_cards rows
     * - detects Troela (3+ aces) and sets up the correct next state
     * @return array{handId:int, troela:bool}
     */
    public static function dealHand(int $gameId): array {
        return DB::tx(function (PDO $pdo) use ($gameId) {

            // --- read game header ---
            $g = $pdo->prepare('SELECT status, hand_number, dealer_seat FROM games WHERE id = ? FOR UPDATE');
            $g->execute([$gameId]);
            $game = $g->fetch();
            if (!$game) throw new RuntimeException("No game $gameId");

            $dealer    = (int) $game['dealer_seat'];
            $handNo    = (int) $game['hand_number'] + 1;
            $leader    = ($dealer + 1) % 4;          // left of dealer leads trick 1

            // --- deal 4-5-4 from the persistent stack (validated engine) ---
            $deck = new Deck();
            $deck->cards = DeckRepository::load($gameId);   // Card[]
            $hands = $deck->deal();                          // Card[][] seats 0..3

            // --- Troela detection ---
            $aceCount = [0, 0, 0, 0];
            foreach ($hands as $seat => $cards) {
                foreach ($cards as $c) if ($c->isAce()) $aceCount[$seat]++;
            }
            $troelaSeat = null; $fourAce = false;
            foreach ($aceCount as $seat => $n) {
                if ($n >= 3) { $troelaSeat = $seat; $fourAce = ($n === 4); }
            }

            // partner (only for the standard 3-ace case here; 4-ace handled in declaring)
            $partnerSeat = null;
            if ($troelaSeat !== null && !$fourAce) {
                foreach ($hands as $seat => $cards) {
                    if ($seat === $troelaSeat) continue;
                    foreach ($cards as $c) if ($c->isAce()) { $partnerSeat = $seat; break 2; }
                }
            }

            // --- snapshot for audit/replay ---
            $snapshot = [];
            foreach ($hands as $seat => $cards) $snapshot[$seat] = cards_to_ids($cards);

            // --- insert the hand row ---
            $isTroela = $troelaSeat !== null;
            $ins = $pdo->prepare(
                'INSERT INTO hands
                   (game_id, hand_number, dealer_seat, leader_seat,
                    contract, declarer_seat, partner_seat,
                    troela_occurred, status, dealt_snapshot)
                 VALUES (?,?,?,?,?,?,?,?,?,?)'
            );
            $ins->execute([
                $gameId, $handNo, $dealer, $leader,
                $isTroela ? 'troela' : null,
                $isTroela ? $troelaSeat : null,
                $partnerSeat,
                $isTroela ? 1 : 0,
                $isTroela ? 'declaring' : 'bidding',
                json_encode($snapshot, JSON_THROW_ON_ERROR),
            ]);
            $handId = (int) $pdo->lastInsertId();

            // --- insert 52 hand_cards ---
            $hc = $pdo->prepare('INSERT INTO hand_cards (hand_id, seat, card) VALUES (?,?,?)');
            foreach ($hands as $seat => $cards) {
                foreach ($cards as $c) $hc->execute([$handId, $seat, $c->id()]);
            }

            // --- set up the next turn on the game row ---
            if ($isTroela) {
                // Troela: skip bidding. Partner chooses trump next.
                // 4-ace edge: declarer will call a King instead (handled in declaring step).
                $nextSeat   = $fourAce ? $troelaSeat : $partnerSeat;
                $nextStatus = 'declaring';
            } else {
                $nextSeat   = $leader;   // bidding opens left of dealer
                $nextStatus = 'bidding';
            }

            $upd = $pdo->prepare(
                'UPDATE games
                    SET hand_number = ?, current_hand_id = ?, current_seat = ?, status = ?
                  WHERE id = ?'
            );
            $upd->execute([$handNo, $handId, $nextSeat, $nextStatus, $gameId]);

            return ['handId' => $handId, 'troela' => $isTroela];
        });
    }

    /** Convenience: the 13 card-ids a seat currently HOLDS (unplayed). */
    public static function seatHand(int $handId, int $seat): array {
        $s = DB::conn()->prepare(
            'SELECT card FROM hand_cards WHERE hand_id = ? AND seat = ? AND played = 0 ORDER BY card'
        );
        $s->execute([$handId, $seat]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }
}