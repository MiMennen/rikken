<?php
declare(strict_types=1);

/**
 * Rikken — Hand evaluation, bidding policy & calibration harness (PHP 8.2)
 * Depends on engine classes from rikken_engine.php (Suit, Card, Deck, Gather).
 * Run: php rikken_bidding.php [numHands=20000]
 *
 * Philosophy: the heuristic makes a CLAIM about a hand; the harness plays the
 * hand out and checks whether reality agreed. We tune weights to CALIBRATION,
 * not to opinion.
 */
require __DIR__ . '/rikken_engine.php';


/* ===================== BIDDING POLICY (v1 scope) ===================== */
/* Thresholds are PLACEHOLDERS — tune them from the calibration output. */
final class BiddingPolicy {
    public const RIK_MIN     = 5.5;  // own expected tricks; partner adds the rest
    public const SOLO8_MIN   = 8.0;  // must reach 8 alone
    public const MISERE_MAX  = 1.0;  // danger at/below this => attempt misère

    /** Returns one of: PASS, RIK, SOLO8, MISERE, TROELA. */
    public static function decide(array $hand): string {
        $aces = HandEvaluator::countAces($hand);
        if ($aces >= 3) return 'TROELA';                 // forced

        $bt = HandEvaluator::bestTrump($hand);
        $m  = HandEvaluator::misereDanger($hand);

        if ($bt['tricks'] >= self::SOLO8_MIN)            return 'SOLO8';
        if ($m <= self::MISERE_MAX)                      return 'MISERE';
        if ($bt['tricks'] >= self::RIK_MIN
            && HandEvaluator::canCallAce($hand))         return 'RIK';
        return 'PASS';
    }
}

/* ===================== CALIBRATION PLAYOUT ===================== */
/* Moderate-skill playout: good enough to grade trick-taking power.
   NOT the final game AI. Declarer plays to its goal; defenders oppose. */
final class SmartPlay {
    /** Solo trump game. Returns tricks won by $declarer. */
    public static function playSolo(array $hands, int $declarer, Suit $trump, int $leader): int {
        $won = 0; $lead = $leader;
        for ($t = 0; $t < 13; $t++) {
            $entries = []; $ledSuit = null; $bestSeat = -1; $bestCard = null;
            for ($s = 0; $s < 4; $s++) {
                $seat = ($lead + $s) % 4;
                $card = self::pickSolo($hands[$seat], $ledSuit, $trump,
                                       $bestSeat, $bestCard, $seat === $declarer, $declarer);
                self::remove($hands[$seat], $card);
                $ledSuit ??= $card->suit;
                $entries[$seat] = $card;
                if ($bestCard === null || cardBeats($card, $bestCard, $ledSuit, $trump)) {
                    $bestCard = $card; $bestSeat = $seat;
                }
            }
            if ($bestSeat === $declarer) $won++;
            $lead = $bestSeat;
        }
        return $won;
    }

    private static function pickSolo(array $hand, ?Suit $ledSuit, ?Suit $trump,
                                     int $bestSeat, ?Card $bestCard,
                                     bool $isDecl, int $declarer): Card {
        $legal = self::legal($hand, $ledSuit);
        if ($ledSuit === null) {                       // we are leading
            return self::maxCard($legal, $trump);      // cash / draw trumps
        }
        $winners = array_values(array_filter(
            $legal, fn(Card $c) => cardBeats($c, $bestCard, $ledSuit, $trump)));
        if ($isDecl) {
            return $winners ? self::minCard($winners, $trump) : self::minCard($legal, $trump);
        }
        // defender: snatch the trick from declarer cheaply; else discard low
        $declWinning = ($bestSeat === $declarer);
        if ($declWinning && $winners) return self::minCard($winners, $trump);
        return self::minCard($legal, $trump);
    }

    /** Misère: declarer wants ZERO tricks. (Defenders here are NAIVE — see report.) */
    public static function playMisere(array $hands, int $declarer, int $leader): int {
        $won = 0; $lead = $leader; $trump = null;
        for ($t = 0; $t < 13; $t++) {
            $ledSuit = null; $bestSeat = -1; $bestCard = null;
            for ($s = 0; $s < 4; $s++) {
                $seat = ($lead + $s) % 4;
                $legal = self::legal($hands[$seat], $ledSuit);
                if ($seat === $declarer) {
                    if ($ledSuit === null) { $card = self::minCard($legal, null); }
                    else {
                        $losers = array_values(array_filter(
                            $legal, fn(Card $c) => !cardBeats($c, $bestCard, $ledSuit, $trump)));
                        $card = $losers ? self::maxCard($losers, null) : self::maxCard($legal, null);
                    }
                } else { // naive defender: dump lowest (forces declarer's highs to win)
                    $card = self::minCard($legal, null);
                }
                self::remove($hands[$seat], $card);
                $ledSuit ??= $card->suit;
                if ($bestCard === null || cardBeats($card, $bestCard, $ledSuit, $trump)) {
                    $bestCard = $card; $bestSeat = $seat;
                }
            }
            if ($bestSeat === $declarer) $won++;
            $lead = $bestSeat;
        }
        return $won;
    }

    private static function legal(array $hand, ?Suit $ledSuit): array {
        if ($ledSuit === null) return $hand;
        $same = array_values(array_filter($hand, fn(Card $c) => $c->suit === $ledSuit));
        return $same ?: $hand;
    }
    private static function maxCard(array $cards, ?Suit $trump): Card {
        usort($cards, fn(Card $a, Card $b) => self::key($b, $trump) <=> self::key($a, $trump));
        return $cards[0];
    }
    private static function minCard(array $cards, ?Suit $trump): Card {
        usort($cards, fn(Card $a, Card $b) => self::key($a, $trump) <=> self::key($b, $trump));
        return $cards[0];
    }
    private static function key(Card $c, ?Suit $trump): int {
        return (($trump !== null && $c->suit === $trump) ? 100 : 0) + $c->rank;
    }
    private static function remove(array &$hand, Card $card): void {
        foreach ($hand as $i => $c) if ($c === $card) { array_splice($hand, $i, 1); return; }
    }
}

/* ===================== HARNESS ===================== */
$numHands = (int) ($argv[1] ?? 20000);

$soloBuckets = [];   // floor(predicted) => ['n','sumActual','made8']
$misBuckets  = [];   // danger bucket    => ['n','made0']
$bidCounts   = ['PASS'=>0,'RIK'=>0,'SOLO8'=>0,'MISERE'=>0,'TROELA'=>0];

$deck = Deck::fresh(); $deck->shuffle();
$dealer = 0;

for ($h = 0; $h < $numHands; $h++) {
    $hands = $deck->deal();

    // Evaluate + classify every seat; calibrate using seat as the candidate declarer
    $troela = false;
    foreach ($hands as $seat => $hand) {
        $bidCounts[BiddingPolicy::decide($hand)]++;
        if (HandEvaluator::countAces($hand) >= 3) $troela = true;

        // ---- SOLO-8 calibration ----
        $bt = HandEvaluator::bestTrump($hand);
        $b  = (int) floor($bt['tricks']);
        $soloBuckets[$b] ??= ['n'=>0,'sum'=>0,'made'=>0];
        $copy = array_map(fn($x)=>$x, $hands);  // shallow copies of seat arrays
        $copy = [$hands[0],$hands[1],$hands[2],$hands[3]];
        $tricks = SmartPlay::playSolo(
            [ $hands[0], $hands[1], $hands[2], $hands[3] ],
            $seat, $bt['suit'], $seat /* declarer leads (optimistic) */);
        $soloBuckets[$b]['n']++;
        $soloBuckets[$b]['sum'] += $tricks;
        if ($tricks >= 8) $soloBuckets[$b]['made']++;

        // ---- MISÈRE calibration (rough; naive defenders) ----
        $m  = HandEvaluator::misereDanger($hand);
        $mb = (int) floor(max(-1.0, min(5.0, $m)));
        $misBuckets[$mb] ??= ['n'=>0,'made0'=>0];
        $mt = SmartPlay::playMisere(
            [ $hands[0], $hands[1], $hands[2], $hands[3] ], $seat, $seat);
        $misBuckets[$mb]['n']++;
        if ($mt === 0) $misBuckets[$mb]['made0']++;
    }

    // advance the validated deck engine
    $trump  = Suit::from(random_int(0,3));
    $piles  = PlaySim::play([$hands[0],$hands[1],$hands[2],$hands[3]], $trump, ($dealer+1)%4);
    if ($troela) { $deck = Deck::fresh(); $deck->shuffle(); }
    else { $deck->cards = Gather::cut(Gather::gather($piles, 0.15)); }
    $dealer = ($dealer + 1) % 4;
}

/* ---------------- REPORT ---------------- */
echo "\n=== SOLO-8 calibration ({$numHands} hands x4 seats) ===\n";
echo "predicted | n      | mean actual tricks | P(>=8) \n";
ksort($soloBuckets);
foreach ($soloBuckets as $b => $d) {
    if ($d['n'] < 30) continue;
    printf("  %2d–%-2d  | %6d | %6.2f             | %5.1f%%\n",
        $b, $b, $d['n'], $d['sum']/$d['n'], 100*$d['made']/$d['n']);
}

echo "\n=== MISÈRE calibration (ROUGH — naive defenders) ===\n";
echo "danger | n      | P(0 tricks)\n";
ksort($misBuckets);
foreach ($misBuckets as $b => $d) {
    if ($d['n'] < 30) continue;
    printf("  %2d   | %6d | %5.1f%%\n", $b, $d['n'], 100*$d['made0']/$d['n']);
}

echo "\n=== Bid distribution (policy intent, per player-hand) ===\n";
$total = array_sum($bidCounts);
foreach ($bidCounts as $k => $v) printf("  %-7s %5.1f%%\n", $k, 100*$v/$total);
echo "\n";