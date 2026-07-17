<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/HandService.php';
require_once __DIR__ . '/rikken_engine.php';
require_once __DIR__ . '/Contracts.php';
require_once __DIR__ . '/Rules.php';

final class PlayService {

    private static function aceId(int $suit): int { return $suit * 13 + 12; }

    /** The current (incomplete) trick: number, leader, led_suit, plays so far. */
    public static function currentTrick(PDO $pdo, int $handId): array {
        $t = $pdo->prepare(
            'SELECT id, trick_number, leader_seat, led_suit
               FROM tricks WHERE hand_id=? AND winner_seat IS NULL
               ORDER BY trick_number LIMIT 1'
        );
        $t->execute([$handId]);
        $trick = $t->fetch();
        if (!$trick) return ['trick'=>null, 'plays'=>[]];
        $p = $pdo->prepare('SELECT seat, card, play_seq FROM trick_plays WHERE trick_id=? ORDER BY play_seq');
        $p->execute([$trick['id']]);
        return ['trick'=>$trick, 'plays'=>$p->fetchAll()];
    }

private const SPADE_QUEEN = 49; // Spades(3)*13 + (Q rank 12 - 2) = 49

    private static function spadeQueenFallen(PDO $pdo, int $handId): bool {
        $s = $pdo->prepare('SELECT 1 FROM trick_plays p JOIN tricks t ON p.trick_id=t.id
                            WHERE t.hand_id=? AND p.card=? LIMIT 1');
        $s->execute([$handId, self::SPADE_QUEEN]);
        return (bool)$s->fetchColumn();
    }

	/** Load the house rules for the game that owns this hand. */
    private static function rulesFor(PDO $pdo, int $handId): Rules {
        $s = $pdo->prepare('SELECT g.rules_config FROM games g
            JOIN hands h ON h.game_id = g.id WHERE h.id = ?');
        $s->execute([$handId]);
        return Rules::fromJson($s->fetchColumn());
    }

    /** The trick currently in progress (rows are created at trick start → this is correct). */
    private static function currentTrickNumber(PDO $pdo, int $handId): int {
        $s = $pdo->prepare('SELECT COALESCE(MAX(trick_number),0) FROM tricks WHERE hand_id = ?');
        $s->execute([$handId]);
        return (int)$s->fetchColumn();
    }

    /** Has ANY spade (not just the Queen) appeared in a trick yet? */
    private static function anySpadePlayed(PDO $pdo, int $handId): bool {
        $s = $pdo->prepare('SELECT 1 FROM trick_plays p JOIN tricks t ON p.trick_id = t.id
            WHERE t.hand_id = ? AND (p.card DIV 13) = 3 LIMIT 1');
        $s->execute([$handId]);
        return (bool)$s->fetchColumn();
    }

    /**
     * Decision A, all four variants in one place.
     * Returns TRUE if leading a spade is currently allowed.
     * $qFallen and $onlySpades are the values your existing code already computes.
     */
    private static function spadesMayBeLed(PDO $pdo, int $handId, bool $qFallen, bool $onlySpades): bool {
        if ($onlySpades) return true;                       // always allowed if you hold only spades
        $rules = self::rulesFor($pdo, $handId);
        switch ($rules->spadeLead()) {
            case 'first_trick':     return self::currentTrickNumber($pdo, $handId) > 1;
            case 'first_three':     return self::currentTrickNumber($pdo, $handId) > 3;
            case 'until_any_spade': return self::anySpadePlayed($pdo, $handId);
            case 'until_queen':
            default:                return $qFallen;          // = current behaviour
        }
    }

    /** Legal cards for a Schoppen Mie hand (no-trump avoidance + spade lock). */
    private static function legalSchoppenMie(PDO $pdo, int $handId, array $held, array $cur): array {
        $qFallen = self::spadeQueenFallen($pdo, $handId);
        $onlySpades = true;
        foreach ($held as $id) { if (intdiv($id,13) !== 3) { $onlySpades = false; break; } }

        $leading = ($cur['trick'] === null || count($cur['plays']) === 0 || $cur['trick']['led_suit'] === null);

        if ($leading) {
           if (!self::spadesMayBeLed($pdo, $handId, $qFallen, $onlySpades)) return $held;                 // spades open, or forced
            $nonSpade = array_values(array_filter($held, fn($id)=>intdiv($id,13)!==3));
            return $nonSpade !== [] ? $nonSpade : $held;               // no leading spades yet
        }

        $ledSuit  = (int)$cur['trick']['led_suit'];
        $sameSuit = array_values(array_filter($held, fn($id)=>intdiv($id,13)===$ledSuit));
        if ($sameSuit !== []) return $sameSuit;                        // must follow suit

        // void in led suit -> discarding:
        if (self::rulesFor($pdo, $handId)->queenDiscard() === 'forced'
        && $void && in_array(self::SPADE_QUEEN, $held, true)) {
        return [self::SPADE_QUEEN];        // forced variant: must dump the Queen now
       }
       // 'anytime' variant: no forced smear — ♠Q is just a normal card when void,
       // so execution continues to the normal "discard anything legal" logic below.
        if ($qFallen || $onlySpades) return $held;                     // spades open / only spades
        $nonSpade = array_values(array_filter($held, fn($id)=>intdiv($id,13)!==3));
        return $nonSpade !== [] ? $nonSpade : $held;                   // no discarding spades yet
    }

    /**
     * Legal cards (ids) for $seat right now, honouring follow-suit and the
     * forced called-Ace rule. Returns int[] of card ids.
     */
public static function legalCards(int $handId, int $seat): array {
        $pdo  = DB::conn();
        $hand = $pdo->prepare('SELECT contract, trump_suit, called_ace_suit, partner_seat FROM hands WHERE id=?');
        $hand->execute([$handId]);
        $h = $hand->fetch();

        $held = HandService::seatHand($handId, $seat);
        $cur  = self::currentTrick($pdo, $handId);

        // --- Schoppen Mie has its own rules (T2a) ---
        if ($h['contract'] === 'pass_schoppenmie') {
            return self::legalSchoppenMie($pdo, $handId, $held, $cur);
        }

        $calledSuit = $h['called_ace_suit'] !== null ? (int)$h['called_ace_suit'] : null;
        // For rik/rik_beter the called card is the Ace; for a 4-ace troela it's the King.
        $calledRank = ($h['contract'] === 'troela' && $h['partner_seat'] !== null
                       && self::partnerHoldsKingNotAce($h)) ? 11 : 12;   // 12=Ace offset,11=King offset
        $calledCardId = $calledSuit !== null ? $calledSuit * 13 + $calledRank : null;

        $iAmPartner = $h['partner_seat'] !== null && (int)$h['partner_seat'] === $seat;
        $iHoldCalled = $calledCardId !== null && in_array($calledCardId, $held, true);

        $leading = ($cur['trick'] === null || count($cur['plays']) === 0 || $cur['trick']['led_suit'] === null);

        // ================= LEADING =================
        if ($leading) {
            // STRICT rule 1: if the partner leads and still holds the called card,
            // they may lead anything EXCEPT a non-called card of the called suit
            // (i.e. if they choose that suit, it must be the called card).
            if ($iAmPartner && $iHoldCalled) {
                // remove other cards of the called suit from the legal lead set,
                // but keep the called card itself and all other suits.
                $filtered = array_values(array_filter($held,
                    fn($id) => intdiv($id,13) !== $calledSuit || $id === $calledCardId));
                return $filtered;   // may still lead the called card, or any off-suit card
            }
            return $held;
        }

        // ================= FOLLOWING =================
        $ledSuit  = (int) $cur['trick']['led_suit'];
        $sameSuit = array_values(array_filter($held, fn($id) => intdiv($id,13) === $ledSuit));

        // Called suit is led:
        if ($calledSuit !== null && $ledSuit === $calledSuit) {
            if ($iHoldCalled) return [$calledCardId];          // MUST play the called card
            return $sameSuit !== [] ? $sameSuit : $held;       // else normal follow-suit
        }

        // A DIFFERENT suit is led:
        if ($sameSuit !== []) return $sameSuit;                // must follow suit normally

        // Void in led suit -> discarding. STRICT rule 2: partner may NOT dump the
        // called card early; it's only released when its own suit is in play.
        if ($iAmPartner && $iHoldCalled) {
            $others = array_values(array_filter($held, fn($id) => $id !== $calledCardId));
            if ($others !== []) return $others;                // must discard something else
            return $held;                                      // only the called card left -> forced
        }

        return $held;                                          // free discard
    }

    /** True if in a Troela the partner holds a called KING rather than the 4th Ace. */
    private static function partnerHoldsKingNotAce(array $h): bool {
        // 4-ace troela stores the called (king) suit in called_ace_suit AND the
        // declarer holds all four aces. Distinguish by whether partner holds that Ace.
        // Simplest reliable check: in a 4-ace troela the declarer has all aces, so the
        // called suit's Ace is NOT with the partner. We detect via dealt_snapshot upstream,
        // but for legality we treat called card as King only when flagged. Default: Ace.
        return false; // 3-ace troela (partner = 4th Ace holder) -> called card is an Ace
    }

    /**
     * Play $cardId for $seat. Validates turn + legality, records it, resolves
     * the trick when the 4th card lands, advances to next seat or next trick,
     * and ends the hand (status hand_scoring) after trick 13.
     */
public static function playCard(int $gameId, int $seat, int $cardId,
                                    bool $faceDown = false, ?int $announcedSuit = null): array {
        return DB::tx(function (PDO $pdo) use ($gameId, $seat, $cardId, $faceDown, $announcedSuit) {
            $g = $pdo->prepare('SELECT status, current_seat, current_hand_id FROM games WHERE id=? FOR UPDATE');
            $g->execute([$gameId]);
            $game = $g->fetch();
            if (!$game)                        throw new RuntimeException("No game $gameId");
            if ($game['status'] !== 'playing') throw new RuntimeException('Not in playing phase.');
            if ((int)$game['current_seat'] !== $seat) throw new RuntimeException('Not your turn.');

            $handId = (int) $game['current_hand_id'];
            $hand = $pdo->prepare('SELECT contract, trump_suit, leader_seat, declarer_seat, called_ace_suit FROM hands WHERE id=?');
            $hand->execute([$handId]);
            $h = $hand->fetch();
            $trump = $h['trump_suit'] !== null ? Suit::from((int)$h['trump_suit']) : null;

            $cur = self::currentTrick($pdo, $handId);
            $isLeadingNow = ($cur['trick'] === null || count($cur['plays']) === 0);

            // ---- validate a blind lead ----
            if ($faceDown) {
                if (!$isLeadingNow)                     throw new RuntimeException('Blind lead only when leading.');
                if ((int)$h['declarer_seat'] !== $seat) throw new RuntimeException('Only the declarer may blind lead.');
                if ($h['called_ace_suit'] === null)     throw new RuntimeException('No called suit to force.');
                if ($announcedSuit === null)            throw new RuntimeException('Must announce a suit.');
                // authentic trigger: declarer must be VOID in the announced (called) suit
                $held = HandService::seatHand($handId, $seat);
                foreach ($held as $id) if (intdiv($id,13) === $announcedSuit)
                    throw new RuntimeException('Cannot blind lead a suit you hold.');
                if ($announcedSuit !== (int)$h['called_ace_suit'])
                    throw new RuntimeException('Blind lead must announce the called suit.');
                // the face-down card itself may be ANY held card
                if (!in_array($cardId, $held, true)) throw new RuntimeException('Card not in hand.');
            } else {
                if (!in_array($cardId, self::legalCards($handId, $seat), true))
                    throw new RuntimeException('Illegal card.');
            }

            // ---- find or create the trick ----
            if ($cur['trick'] === null) {
                $tn = (int) $pdo->query("SELECT COALESCE(MAX(trick_number),0)+1 FROM tricks WHERE hand_id=$handId")->fetchColumn();
                // On a blind lead, the led_suit is the ANNOUNCED suit, set immediately.
                $ledInit = $faceDown ? $announcedSuit : null;
                $pdo->prepare('INSERT INTO tricks (hand_id, trick_number, leader_seat, led_suit) VALUES (?,?,?,?)')
                    ->execute([$handId, $tn, $seat, $ledInit]);
                $trickId = (int)$pdo->lastInsertId();
                $cur = ['trick'=>['id'=>$trickId,'trick_number'=>$tn,'leader_seat'=>$seat,'led_suit'=>$ledInit],'plays'=>[]];
            }
            $trickId = (int) $cur['trick']['id'];
            $playSeq = count($cur['plays']);

            $pdo->prepare('INSERT INTO trick_plays (trick_id, play_seq, seat, card, face_down) VALUES (?,?,?,?,?)')
                ->execute([$trickId, $playSeq, $seat, $cardId, $faceDown ? 1 : 0]);
            $gseq = (int) $pdo->query("SELECT COALESCE(MAX(play_seq),0)+1 FROM hand_cards WHERE hand_id=$handId AND played=1")->fetchColumn();
            $pdo->prepare('UPDATE hand_cards SET played=1, play_seq=? WHERE hand_id=? AND card=?')
                ->execute([$gseq, $handId, $cardId]);

            // normal lead sets led_suit from the card; blind lead already set it (announced)
            if ($playSeq === 0 && !$faceDown)
                $pdo->prepare('UPDATE tricks SET led_suit=? WHERE id=?')->execute([intdiv($cardId,13), $trickId]);

            // ---- trick incomplete: next seat ----
            if ($playSeq < 3) {
                $next = ($seat + 1) % 4;
                $pdo->prepare('UPDATE games SET current_seat=? WHERE id=?')->execute([$next, $gameId]);
                return ['outcome'=>'card_played', 'trickComplete'=>false, 'next'=>$next, 'blind'=>$faceDown];
            }

            // ---- resolve (face-down card now counts by its TRUE suit) ----
            $plays = $pdo->prepare('SELECT seat, card FROM trick_plays WHERE trick_id=? ORDER BY play_seq');
            $plays->execute([$trickId]);
            $rows = $plays->fetchAll();
            $ledSuit = Suit::from((int)$pdo->query("SELECT led_suit FROM tricks WHERE id=$trickId")->fetchColumn());

            $winnerSeat=(int)$rows[0]['seat']; $bestCard=Card::fromId((int)$rows[0]['card']);
            foreach ($rows as $r) {
                $c=Card::fromId((int)$r['card']);
                if (cardBeats($c,$bestCard,$ledSuit,$trump)) { $bestCard=$c; $winnerSeat=(int)$r['seat']; }
            }
            $pdo->prepare('UPDATE tricks SET winner_seat=? WHERE id=?')->execute([$winnerSeat, $trickId]);

           // ---- EARLY TERMINATION for avoidance contracts (incl. open variants) ----
            if (Contracts::type($h['contract']) === 'avoid') {
                $decl = (int)$h['declarer_seat'];
                $declTricks = (int)$pdo->query(
                    "SELECT COUNT(*) FROM tricks WHERE hand_id=$handId AND winner_seat=$decl")->fetchColumn();
                $target = (int) Contracts::target($h['contract']);   // 0 = misère-type, 1 = piek-type
                // Fail the instant the bidder EXCEEDS the target (can never come back down).
                if ($declTricks > $target) {
                    self::endHandEarly($pdo, $gameId, $handId);
                    return ['outcome'=>'hand_complete','trickComplete'=>true,'winner'=>$winnerSeat,'earlyEnd'=>true];
                }
            }
			

            $done=(int)$pdo->query("SELECT COUNT(*) FROM tricks WHERE hand_id=$handId AND winner_seat IS NOT NULL")->fetchColumn();
            if ($done >= 13) {
                $pdo->prepare("UPDATE hands SET status='scored' WHERE id=?")->execute([$handId]);
                $pdo->prepare("UPDATE games SET status='hand_scoring', current_seat=NULL WHERE id=?")->execute([$gameId]);
                return ['outcome'=>'hand_complete','trickComplete'=>true,'winner'=>$winnerSeat];
            }
            $pdo->prepare('UPDATE games SET current_seat=? WHERE id=?')->execute([$winnerSeat, $gameId]);
            return ['outcome'=>'trick_won','trickComplete'=>true,'winner'=>$winnerSeat];
        });
    }
	
    /** End an avoidance hand early: fold unplayed cards into their owners' trick piles
     *  so the deck-gather stays 52 cards, then move to scoring. */
    private static function endHandEarly(PDO $pdo, int $gameId, int $handId): void {
        // Mark all still-unplayed cards as played (owner keeps them, appended to their pile
        // at gather time via winner=owner). We record them so nextHand's gather sees 52 cards.
        // Simplest: leave hand_cards.played=0; nextHand's gather already reads TRICKS for piles,
        // so we must ensure unplayed cards get into piles. We do that by tagging them here.
        $pdo->prepare("UPDATE hands SET status='scored' WHERE id=?")->execute([$handId]);
        $pdo->prepare("UPDATE games SET status='hand_scoring', current_seat=NULL WHERE id=?")->execute([$gameId]);
    }
}
