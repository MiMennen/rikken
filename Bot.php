<?php
declare(strict_types=1);
require_once __DIR__ . '/rikken_engine.php';
require_once __DIR__. '/AvoidanceAI.php';

final class Bot {

    /**
     * Decide a bid given the bot's hand and the current highest bid RANK.
     * Ranks: pass=0, rik=1, rik_beter=2, solo8=3.  (Troela handled at deal;
     * Misère deferred to v2.)  Returns one of pass|rik|rik_beter|solo8.
     */
	public const RIK_MIN    = 5.0;
    public const SOLO8_MIN  = 8.5;
    public const SOLO9_MIN  = 9.0;
    public const SOLO10_MIN = 10.0;
    public const SOLO11_MIN = 10.5;
    public const SOLO12_MIN = 11.5;
    public const SOLO13_MIN = 12.5;

    /** Highest solo the hand qualifies for (or null). */
    private static function bestSolo(float $tricks): ?string {
        if ($tricks >= self::SOLO13_MIN) return 'solo13';
        if ($tricks >= self::SOLO12_MIN) return 'solo12';
        if ($tricks >= self::SOLO11_MIN) return 'solo11';
        if ($tricks >= self::SOLO10_MIN) return 'solo10';
        if ($tricks >= self::SOLO9_MIN)  return 'solo9';
        if ($tricks >= self::SOLO8_MIN)  return 'solo8';
        return null;
    }

public static function chooseBid(array $cards, int $currentRank, int $seat = 0): string {
        if (HandEvaluator::countAces($cards) >= 3) return 'troela';

        $bt = HandEvaluator::bestTrump($cards);
        $leader = ($seat + 1) % 4;

        // --- avoidance contracts (determinization, threshold 0.90) ---
        $canPiek   = Contracts::rank('piek')   > $currentRank;
        $canMisere = Contracts::rank('misere') > $currentRank;
        $pPiek   = $canPiek   ? AvoidanceAI::pMake('piek',   $cards, $seat, $leader, 40) : 0.0;
        $pMisere = $canMisere ? AvoidanceAI::pMake('misere', $cards, $seat, $leader, 40) : 0.0;
		$canOpenMis  = Contracts::rank('open_misere') > $currentRank;
        $canOpenPiek = Contracts::rank('open_piek')   > $currentRank;
        $pOpenMis  = $canOpenMis  ? AvoidanceAI::pMakeOpen('open_misere', $cards, $seat, 40) : 0.0;
        $pOpenPiek = $canOpenPiek ? AvoidanceAI::pMakeOpen('open_piek',   $cards, $seat, 40) : 0.0;
        if ($pOpenMis  >= 0.90) $candidates['open_misere'] = Contracts::rank('open_misere');
        if ($pOpenPiek >= 0.90) $candidates['open_piek']   = Contracts::rank('open_piek');
		$canPraatje = Contracts::rank('open_misere_praatje') > $currentRank;
        $pPraatje = $canPraatje ? AvoidanceAI::pMakeOpen('open_misere_praatje', $cards, $seat, 40) : 0.0;
        if ($pPraatje >= 0.90) $candidates['open_misere_praatje'] = Contracts::rank('open_misere_praatje');

        // best solo this hand can attempt (existing)
        $solo = self::bestSolo($bt['tricks']);

        // choose the HIGHEST-ranked contract we qualify for
        $candidates = [];
        if ($solo !== null)        $candidates[$solo]      = Contracts::rank($solo);
        if ($pMisere >= 0.90)      $candidates['misere']   = Contracts::rank('misere');
        if ($pPiek   >= 0.90)      $candidates['piek']     = Contracts::rank('piek');
        if ($bt['tricks'] >= self::RIK_MIN && HandEvaluator::canCallAce($cards))
                                   $candidates['rik']      = Contracts::rank('rik');
        // rik_beter handled as a raise below

        // pick the highest-rank candidate that beats currentRank
        arsort($candidates);
        foreach ($candidates as $bid => $rank)
            if ($rank > $currentRank) {
                // special: prefer rik_beter as a raise over an existing rik
                if ($bid === 'rik' && $currentRank >= Contracts::rank('rik')
                    && HandEvaluator::trumpEval($cards, Suit::Hearts) >= self::RIK_MIN
                    && HandEvaluator::canCallAce($cards, Suit::Hearts->value))
                    return 'rik_beter';
                return $bid;
            }
        return 'pass';
    }
	
/** Returns [trumpSuitOrNull, calledSuitOrNull] for the current declaration. */
    public static function chooseDeclaration(int $handId, int $seat): array {
        $h = DB::conn()->prepare('SELECT contract, partner_seat FROM hands WHERE id=?');
        $h->execute([$handId]);
        $row = $h->fetch();
        $cards = ids_to_cards(HandService::seatHand($handId, $seat));

        switch ($row['contract']) {
            case 'rik':       return self::rikTrumpAndAce($cards, null);
            case 'rik_beter': return self::rikTrumpAndAce($cards, Suit::Hearts->value);
            case 'solo8':     return [HandEvaluator::bestTrump($cards)['suit']->value, null];
			case 'solo9':     return [HandEvaluator::bestTrump($cards)['suit']->value, null];
			case 'solo10':     return [HandEvaluator::bestTrump($cards)['suit']->value, null];
			case 'solo11':     return [HandEvaluator::bestTrump($cards)['suit']->value, null];
			case 'solo12':     return [HandEvaluator::bestTrump($cards)['suit']->value, null];
			case 'solo13':     return [HandEvaluator::bestTrump($cards)['suit']->value, null];
			case 'misere':
            case 'piek':
                return [null, null];   // no trump, no called card
            case 'troela':
                if ($row['partner_seat'] !== null) {        // normal: partner picks trump
                    $forbidden = HandEvaluator::aceSuits($cards)[0] ?? null;
                    return [HandEvaluator::bestTrump($cards, $forbidden)['suit']->value, null];
                }
                // 4-ace: declarer picks trump + calls a King
                return [HandEvaluator::bestTrump($cards)['suit']->value,
                        HandEvaluator::bestCalledKingSuit($cards)];
        }
        throw new RuntimeException('Unexpected contract in declaration.');
    }

    /** Pick trump (optionally forced) such that a callable Ace exists. */
    private static function rikTrumpAndAce(array $cards, ?int $forcedTrump): array {
        if ($forcedTrump !== null) {
            return [$forcedTrump, HandEvaluator::bestCalledAceSuit($cards, $forcedTrump)];
        }
        // try trumps in order of strength; pick first that leaves a callable Ace
        $order = Suit::cases();
        usort($order, fn($a,$b)=>HandEvaluator::trumpEval($cards,$b) <=> HandEvaluator::trumpEval($cards,$a));
        foreach ($order as $s) {
            $ace = HandEvaluator::bestCalledAceSuit($cards, $s->value);
            if ($ace !== null) return [$s->value, $ace];
        }
        // fallback (shouldn't trigger: bid required a callable Ace)
        return [$order[0]->value, HandEvaluator::bestCalledAceSuit($cards, $order[0]->value)];
    }
/** Choose a card to play. Mirrors the RikPlay/SmartPlay logic we calibrated. */
public static function chooseCard(int $gameId, int $handId, int $seat): int {
        $pdo   = DB::conn();
        $legal = PlayService::legalCards($handId, $seat);
        if (count($legal) === 1) return $legal[0];

        $h = $pdo->prepare('SELECT trump_suit, partner_seat, declarer_seat, contract, called_ace_suit FROM hands WHERE id=?');
        $h->execute([$handId]);
        $hand = $h->fetch();
        $cur  = PlayService::currentTrick($pdo, $handId);

        if ($hand['contract'] === 'pass_schoppenmie')
            return self::chooseSchoppenMie($legal, $cur);

        $trump    = $hand['trump_suit'] !== null ? (int)$hand['trump_suit'] : null;
		$calledSuit = $hand['called_ace_suit'] !== null ? (int)$hand['called_ace_suit'] : null;
        $declarer = (int)$hand['declarer_seat'];
        $partner  = $hand['partner_seat'] !== null ? (int)$hand['partner_seat'] : null;
        $sideSet  = [$declarer => true];
        if ($partner !== null) $sideSet[$partner] = true;
        $iAmSide  = isset($sideSet[$seat]);
        $cards    = array_map(fn($id)=>Card::fromId($id), $legal);
		
		if (in_array($hand['contract'], ['misere','piek','open_misere','open_piek','open_misere_praatje'], true)) {
            $bidder = (int)$hand['declarer_seat'];
            $bidderWon = (int)$pdo->query("SELECT COUNT(*) FROM tricks
                WHERE hand_id=$handId AND winner_seat=$bidder")->fetchColumn();
            $ledSuit = ($cur['trick'] && $cur['trick']['led_suit']!==null) ? (int)$cur['trick']['led_suit'] : null;
            if (Contracts::isOpen($hand['contract'])) {
                $bidderHandIds = HandService::seatHand($handId, $bidder);  // face-up: defenders see it
                return AvoidanceAI::chooseCardOpen($hand['contract'], $legal, $ledSuit,
                           $cur['plays'], $seat, $bidder, $bidderWon, $bidderHandIds);
            }
            return AvoidanceAI::chooseCard($hand['contract'], $legal, $ledSuit,
                       $cur['plays'], $seat, $bidder, $bidderWon);
        }

        // ---- leading ----
        if ($cur['trick'] === null || $cur['trick']['led_suit'] === null || count($cur['plays']) === 0) {
            if ($iAmSide && $trump !== null) {
                $trumps = array_values(array_filter($cards, fn(Card $c)=>$c->suit->value===$trump));
                if ($trumps) {
                    $stopDrawing = false;

                    // If no trumps remain in other hands, stop drawing.
                    // For an as-yet-unconfirmed Rik, switch to forcing the ace:
                    // lead the called suit so the partner must reveal.
                    if (self::$drawTrumpSmart && self::noTrumpsOutstanding($pdo, $handId, $trump, $cards)) {
                        if ($calledSuit !== null && !self::calledCardFallen($pdo, $handId)) {
                            $callLead = array_values(array_filter(
                                $cards, fn(Card $c) => $c->suit->value === $calledSuit));
                            if ($callLead) return self::pick($callLead, $trump, true); // force the ace out
                        }
                        $stopDrawing = true; // no ace to chase (solo, or already revealed): keep trumps
                    }
					// I legitimately know my side? Solo = I'm the whole side (no hidden partner);
                    // partner always knows; declarer only after the called card falls.
                    $knowsSide = $partner === null
                              || $seat === $partner
                              || self::calledCardFallen($pdo, $handId);
                    if (self::$drawTrumpSmart && $knowsSide) {
                        $opps  = array_values(array_filter([0,1,2,3], fn($s)=>!isset($sideSet[$s])));
                        $voids = self::trumpVoidSeats($pdo, $handId, $trump);
                        if (count(array_intersect($opps, $voids)) === count($opps))
                            $stopDrawing = true;   // opponents dry -> keep our trumps, cash side winners
                    }
                    if (!$stopDrawing) return self::pick($trumps, $trump, true);   // draw trumps
                }
            }
            $non = array_values(array_filter($cards, fn(Card $c)=>$trump===null || $c->suit->value!==$trump));
            return self::pick($non ?: $cards, $trump, $iAmSide);
        }

        // ---- following (unchanged) ----
        $ledSuit   = (int)$cur['trick']['led_suit'];
        $trumpSuit = $trump !== null ? Suit::from($trump) : null;
        $bestSeat  = (int)$cur['plays'][0]['seat']; $bestCard = Card::fromId((int)$cur['plays'][0]['card']);
        foreach ($cur['plays'] as $p) {
            $c = Card::fromId((int)$p['card']);
            if (cardBeats($c, $bestCard, Suit::from($ledSuit), $trumpSuit)) { $bestCard=$c; $bestSeat=(int)$p['seat']; }
        }
        $teammateWinning = (isset($sideSet[$bestSeat]) === $iAmSide);
        $winners = array_values(array_filter($cards,
            fn(Card $c)=>cardBeats($c, $bestCard, Suit::from($ledSuit), $trumpSuit)));

        if ($teammateWinning) return self::pick($cards, $trump, false);
        if ($winners)         return self::pick($winners, $trump, false);
        return self::pick($cards, $trump, false);
    }

    /** Schoppen Mie: avoid winning the ♠Q trick and the last trick. No trump. */
    private static function chooseSchoppenMie(array $legal, array $cur): int {
        $cards = array_map(fn($id)=>Card::fromId($id), $legal);
        $leading = ($cur['trick'] === null || $cur['trick']['led_suit'] === null || count($cur['plays'])===0);

        if ($leading) {                                                // lead low to avoid winning
            usort($cards, fn($a,$b)=>$a->rank <=> $b->rank);
            return $cards[0]->id();
        }

        $ledSuit = (int)$cur['trick']['led_suit'];
        $bestRank = -1;
        foreach ($cur['plays'] as $p) {
            $c = Card::fromId((int)$p['card']);
            if ($c->suit->value === $ledSuit && $c->rank > $bestRank) $bestRank = $c->rank;
        }

        $followers = array_values(array_filter($cards, fn(Card $c)=>$c->suit->value===$ledSuit));
        if ($followers) {
            $losing = array_values(array_filter($followers, fn(Card $c)=>$c->rank < $bestRank));
            if ($losing) {                                             // shed highest SAFE card
                usort($losing, fn($a,$b)=>$b->rank <=> $a->rank);
                return $losing[0]->id();
            }
            usort($followers, fn($a,$b)=>$b->rank <=> $a->rank);       // must win -> shed highest
            return $followers[0]->id();
        }

        usort($cards, fn($a,$b)=>$b->rank <=> $a->rank);               // discard: dump highest danger
        return $cards[0]->id();
    }

    private static function trickHasSide(array $cur, array $sideSet): bool { return true; }

    /** $high=true -> highest, else lowest (trump-aware ranking). Returns card id. */
    private static function pick(array $cards, ?int $trump, bool $high): int {
        usort($cards, function(Card $a, Card $b) use ($trump) {
            $ka = (($trump!==null && $a->suit->value===$trump)?100:0) + $a->rank;
            $kb = (($trump!==null && $b->suit->value===$trump)?100:0) + $b->rank;
            return $ka <=> $kb;
        });
        return $high ? end($cards)->id() : $cards[0]->id();
    }
	// A/B toggle so we can MEASURE the change (see verify harness).
    public static bool $drawTrumpSmart = true;

    /** Seats observed void in trump: they discarded a non-trump on a trump-led trick. */
    private static function trumpVoidSeats(PDO $pdo, int $handId, int $trump): array {
        $rows = $pdo->query("SELECT t.led_suit, p.seat, p.card
            FROM tricks t JOIN trick_plays p ON p.trick_id=t.id
            WHERE t.hand_id=$handId ORDER BY t.trick_number, p.play_seq")->fetchAll();
        $void = [];
        foreach ($rows as $r) {
            if ((int)$r['led_suit'] === $trump && intdiv((int)$r['card'],13) !== $trump)
                $void[(int)$r['seat']] = true;
        }
        return array_keys($void);
    }
	
	/** True if the bot holds every trump not yet played (none outstanding elsewhere). */
    private static function noTrumpsOutstanding(PDO $pdo, int $handId, int $trump, array $cards): bool {
        $played = (int)$pdo->query(
            "SELECT COUNT(*) FROM trick_plays p JOIN tricks t ON p.trick_id=t.id
             WHERE t.hand_id=$handId AND (p.card DIV 13)=$trump")->fetchColumn();
        $mine = 0;
        foreach ($cards as $c) if ($c->suit->value === $trump) $mine++;
        return ($played + $mine) >= 13; // 13 trumps total; the rest are all in my hand
    }

    /** Has the card that publicly reveals the partner been played yet? */
    private static function calledCardFallen(PDO $pdo, int $handId): bool {
        $h = $pdo->prepare('SELECT contract, called_ace_suit, declarer_seat, dealt_snapshot FROM hands WHERE id=?');
        $h->execute([$handId]); $r = $h->fetch();
        $reveal = null;
        if ($r['contract']==='rik' || $r['contract']==='rik_beter') {
            if ($r['called_ace_suit'] !== null) $reveal = (int)$r['called_ace_suit']*13 + 12;   // called Ace
        } elseif ($r['contract']==='troela') {
            if ($r['called_ace_suit'] !== null) $reveal = (int)$r['called_ace_suit']*13 + 11;   // 4-ace: King
            else {                                                                              // 3-ace: 4th Ace
                $snap = json_decode($r['dealt_snapshot'], true); $d = (int)$r['declarer_seat'];
                $declAce = [];
                foreach ($snap[$d] as $cid) if ($cid % 13 === 12) $declAce[] = intdiv($cid,13);
                foreach ([0,1,2,3] as $sv) if (!in_array($sv,$declAce,true)) { $reveal = $sv*13+12; break; }
            }
        }
        if ($reveal === null) return false;
        $s = $pdo->prepare('SELECT 1 FROM trick_plays p JOIN tricks t ON p.trick_id=t.id
                            WHERE t.hand_id=? AND p.card=? LIMIT 1');
        $s->execute([$handId, $reveal]);
        return (bool)$s->fetchColumn();
    }
}
