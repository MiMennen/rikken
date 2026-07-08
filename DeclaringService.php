<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/HandService.php';
require_once __DIR__ . '/rikken_engine.php';

final class DeclaringService {

    /** card id helpers */
    private static function aceId(int $suit): int  { return $suit * 13 + 12; }
    private static function kingId(int $suit): int { return $suit * 13 + 11; }

    private static function cardOwner(PDO $pdo, int $handId, int $cardId): ?int {
        $s = $pdo->prepare('SELECT seat FROM hand_cards WHERE hand_id=? AND card=?');
        $s->execute([$handId, $cardId]);
        $v = $s->fetchColumn();
        return $v === false ? null : (int) $v;
    }
    private static function seatHolds(PDO $pdo, int $handId, int $seat, int $cardId): bool {
        $s = $pdo->prepare('SELECT 1 FROM hand_cards WHERE hand_id=? AND seat=? AND card=?');
        $s->execute([$handId, $seat, $cardId]);
        return (bool) $s->fetchColumn();
    }
    private static function seatOwnsSuit(PDO $pdo, int $handId, int $seat, int $suit): bool {
        $s = $pdo->prepare('SELECT 1 FROM hand_cards WHERE hand_id=? AND seat=? AND card BETWEEN ? AND ? LIMIT 1');
        $s->execute([$handId, $seat, $suit * 13, $suit * 13 + 12]);
        return (bool) $s->fetchColumn();
    }

    /**
     * Apply a declaration by $seat: $trumpSuit (0..3 or null) and $calledSuit (0..3 or null).
     * Validates per-contract, sets trump/partner/called_ace_suit, transitions to 'playing'.
     */
    public static function applyDeclaration(int $gameId, int $seat, ?int $trumpSuit, ?int $calledSuit): array {
        return DB::tx(function (PDO $pdo) use ($gameId, $seat, $trumpSuit, $calledSuit) {
            $g = $pdo->prepare('SELECT status, current_seat, current_hand_id FROM games WHERE id=? FOR UPDATE');
            $g->execute([$gameId]);
            $game = $g->fetch();
            if (!$game)                          throw new RuntimeException("No game $gameId");
            if ($game['status'] !== 'declaring') throw new RuntimeException('Not in declaring phase.');
            if ((int)$game['current_seat'] !== $seat) throw new RuntimeException('Not your turn.');

            $handId = (int) $game['current_hand_id'];
            $h = $pdo->prepare('SELECT contract, declarer_seat, partner_seat, leader_seat FROM hands WHERE id=?');
            $h->execute([$handId]);
            $hand = $h->fetch();
            $contract    = $hand['contract'];
            $partnerSeat = $hand['partner_seat'] !== null ? (int) $hand['partner_seat'] : null;
            $calledAceSuit = null;
            $finalTrump  = $trumpSuit;

            $actorCards = ids_to_cards(HandService::seatHand($handId, $seat));

            switch ($contract) {
                case 'rik':
                case 'rik_beter':
                    if ($contract === 'rik_beter') $finalTrump = Suit::Hearts->value;
                    if ($finalTrump === null) throw new RuntimeException('Must choose trump.');
                    if ($calledSuit === null) throw new RuntimeException('Must call an Ace.');
                    if ($calledSuit === $finalTrump) throw new RuntimeException('Called Ace must be a non-trump suit.');
                    if (!self::seatOwnsSuit($pdo, $handId, $seat, $calledSuit))
                        throw new RuntimeException('Must hold the called suit.');
                    if (self::seatHolds($pdo, $handId, $seat, self::aceId($calledSuit)))
                        throw new RuntimeException('Cannot call an Ace you hold.');
                    $partnerSeat   = self::cardOwner($pdo, $handId, self::aceId($calledSuit));
                    $calledAceSuit = $calledSuit;
                    break;

				case 'solo8': case 'solo9': case 'solo10':
                case 'solo11': case 'solo12': case 'solo13':
                    if ($finalTrump === null) throw new RuntimeException('Must choose trump.');
                    $partnerSeat = null;
                    break;

                case 'troela':
                    if ($finalTrump === null) throw new RuntimeException('Must choose trump.');
                    if ($partnerSeat !== null) {
                        // normal 3-ace: actor IS the partner; trump != own Ace's suit
                        $forbidden = HandEvaluator::aceSuits($actorCards);
                        if (in_array($finalTrump, $forbidden, true))
                            throw new RuntimeException('Trump cannot be the suit of the 4th Ace.');
                    } else {
                        // 4-ace edge: actor is declarer; call a King -> partner
                        if ($calledSuit === null) throw new RuntimeException('Must call a King.');
                        if (self::seatHolds($pdo, $handId, $seat, self::kingId($calledSuit)))
                            throw new RuntimeException('Cannot call a King you hold.');
                        $partnerSeat   = self::cardOwner($pdo, $handId, self::kingId($calledSuit));
                        $calledAceSuit = $calledSuit; // store called (king) suit here
                    }
                    break;
				
				case 'misere':
                case 'piek':
                    $finalTrump  = null;   // no trump
                    $partnerSeat = null;   // no partner
                    // nothing to validate — declaration is empty
                    break;

                default:
                    throw new RuntimeException("Contract $contract does not declare.");
            }

            $leader = (int) $hand['leader_seat'];
            $pdo->prepare('UPDATE hands SET trump_suit=?, called_ace_suit=?, partner_seat=?, status=? WHERE id=?')
                ->execute([$finalTrump, $calledAceSuit, $partnerSeat, 'playing', $handId]);
            $pdo->prepare('UPDATE games SET status=?, current_seat=? WHERE id=?')
                ->execute(['playing', $leader, $gameId]);

            return ['outcome'=>'declared', 'trump'=>$finalTrump, 'partner'=>$partnerSeat, 'calledSuit'=>$calledAceSuit];
        });
    }
}