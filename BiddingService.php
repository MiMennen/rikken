<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/HandService.php';
require_once __DIR__ . '/Contracts.php';
require_once __DIR__ . '/rikken_engine.php';

final class BiddingService {

    /** Ladder rank of a bid (pass = 0). */
    private static function rankOf(string $bid): int {
        return $bid === 'pass' ? 0 : Contracts::rank($bid);
    }

    /**
     * Current bidding state from the log.
     * Returns active seats, the highest rank, the highest BID NAME, the highest
     * bidder seat, and how many bid-actions have occurred.
     */
    public static function state(int $handId): array {
        $rows = DB::conn()->prepare('SELECT seat, bid FROM bids WHERE hand_id = ? ORDER BY seq');
        $rows->execute([$handId]);

        $passed = [];
        $highestRank = 0;
        $highestBid = null;          // the winning bid's NAME (e.g. 'solo9')
        $highestBidder = null;
        $n = 0;

        foreach ($rows as $r) {
            $n++;
            if ($r['bid'] === 'pass') { $passed[(int)$r['seat']] = true; continue; }
            $rank = self::rankOf($r['bid']);
            if ($rank > $highestRank) {
                $highestRank   = $rank;
                $highestBid    = $r['bid'];
                $highestBidder = (int)$r['seat'];
            }
        }
        $active = array_values(array_filter([0,1,2,3], fn($s) => !isset($passed[$s])));

        return [
            'active'        => $active,
            'highestRank'   => $highestRank,
            'highestBid'    => $highestBid,
            'highestBidder' => $highestBidder,
            'numActions'    => $n,
        ];
    }

    private static function nextActiveSeat(int $from, array $active): int {
        for ($i = 1; $i <= 4; $i++) {
            $s = ($from + $i) % 4;
            if (in_array($s, $active, true)) return $s;
        }
        throw new RuntimeException('No active seat found.');
    }

    /**
     * Apply a bid. Validates turn, biddability, strict-raise, and Rik ace-callability.
     * Transitions to declaring (declarer found), playing (all passed -> Schoppen Mie),
     * or continues bidding.
     */
    public static function applyBid(int $gameId, int $seat, string $bid): array {
        return DB::tx(function (PDO $pdo) use ($gameId, $seat, $bid) {
            $g = $pdo->prepare('SELECT status, current_seat, current_hand_id FROM games WHERE id = ? FOR UPDATE');
            $g->execute([$gameId]);
            $game = $g->fetch();
            if (!$game)                              throw new RuntimeException("No game $gameId");
            if ($game['status'] !== 'bidding')       throw new RuntimeException('Not in bidding phase.');
            if ((int)$game['current_seat'] !== $seat) throw new RuntimeException('Not your turn.');

            // legality of the bid itself
            if ($bid !== 'pass' && !Contracts::isBiddable($bid))
                throw new RuntimeException("Contract '$bid' is not biddable.");

            $handId = (int) $game['current_hand_id'];
            $pre = self::state($handId);

            if ($bid !== 'pass') {
                if (self::rankOf($bid) <= $pre['highestRank'])
                    throw new RuntimeException('Bid must strictly outrank the current bid.');

                if ($bid === 'rik_beter') {
                    $cards = ids_to_cards(HandService::seatHand($handId, $seat));
                    if (!HandEvaluator::canCallAce($cards, Suit::Hearts->value))
                        throw new RuntimeException('Cannot bid Rik Beter without a callable non-hearts Ace.');
                } elseif ($bid === 'rik') {
                    $cards = ids_to_cards(HandService::seatHand($handId, $seat));
                    if (!HandEvaluator::canCallAce($cards))
                        throw new RuntimeException('Cannot bid Rik without a callable Ace.');
                }
            }

            $ins = $pdo->prepare('INSERT INTO bids (hand_id, seq, seat, bid) VALUES (?,?,?,?)');
            $ins->execute([$handId, $pre['numActions'] + 1, $seat, $bid]);

            $post = self::state($handId);

            // --- all passed -> Schoppen Mie ---
            if (count($post['active']) === 0) {
                $leader = (int) $pdo->query("SELECT leader_seat FROM hands WHERE id=$handId")->fetchColumn();
                $pdo->prepare("UPDATE hands SET contract='pass_schoppenmie', status='playing' WHERE id=?")->execute([$handId]);
                $pdo->prepare("UPDATE games SET status='playing', current_seat=? WHERE id=?")->execute([$leader, $gameId]);
                return ['outcome' => 'schoppenmie'];
            }

            // --- declarer found (one active seat left, and it's the highest bidder) ---
            // --- declarer found ---
            if (count($post['active']) === 1
                && $post['highestBidder'] !== null
                && $post['active'][0] === $post['highestBidder']) {

                $declarer = $post['highestBidder'];
                $contract = $post['highestBid'];

                // Avoidance contracts (Misère/Piek): nothing to declare -> straight to play.
                if (Contracts::type($contract) === 'avoid') {
                    $leader = Contracts::isOpen($contract)
                        ? $declarer                                  // open: declarer leads
                        : (int) $pdo->query("SELECT leader_seat FROM hands WHERE id=$handId")->fetchColumn();
                    $pdo->prepare("UPDATE hands SET contract=?, declarer_seat=?, trump_suit=NULL,
                                   partner_seat=NULL, leader_seat=?, status='playing' WHERE id=?")
                        ->execute([$contract, $declarer, $leader, $handId]);
                    $pdo->prepare("UPDATE games SET status='playing', current_seat=? WHERE id=?")
                        ->execute([$leader, $gameId]);
                    return ['outcome'=>'declared_avoid','seat'=>$declarer,'contract'=>$contract];
                }

                // normal path (rik/solo/etc.)
                $trump = Contracts::forcedHearts($contract) ? Suit::Hearts->value : null;
                $pdo->prepare("UPDATE hands SET contract=?, declarer_seat=?, trump_suit=?, status='declaring' WHERE id=?")
                    ->execute([$contract, $declarer, $trump, $handId]);
                $pdo->prepare("UPDATE games SET status='declaring', current_seat=? WHERE id=?")
                    ->execute([$declarer, $gameId]);
                return ['outcome'=>'declarer', 'seat'=>$declarer, 'contract'=>$contract];
            }

            // --- continue bidding ---
            $next = self::nextActiveSeat($seat, $post['active']);
            $pdo->prepare("UPDATE games SET current_seat=? WHERE id=?")->execute([$next, $gameId]);
            return ['outcome' => 'continue', 'next' => $next];
        });
    }
}