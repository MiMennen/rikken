<?php
declare(strict_types=1);
/**
 * Rikken — Rik partner-aware calibration (PHP 8.2)
 * Measures: given a declarer's OWN predicted tricks, how often does the
 * declaring SIDE (declarer + holder-of-called-Ace) reach 8 in 2-v-2 play?
 *
 * Play level = "pub regular": draw trumps, cash winners, duck for partner,
 * win cheaply, honour the forced called-Ace rule. NOT a card-counting shark.
 */
require __DIR__ . '/rikken_engine.php';   // Suit, Card, Deck, Gather, PlaySim,
                                          // cardBeats(), HandEvaluator

/* ===================== CALLED-ACE CHOICE ===================== */
final class RikChoice {
    /** Best legal Ace to call: longest non-trump suit we hold but lack the Ace;
     *  tie-break = holding the King, then total rank. Returns suit value or null. */
    public static function calledAceSuit(array $hand, Suit $trump): ?int {
        $g = HandEvaluator::bySuit($hand);
        $best = null; $bestScore = -1;
        foreach ([0,1,2,3] as $sv) {
            if ($sv === $trump->value) continue;
            $ranks = $g[$sv];
            if (count($ranks) < 1) continue;            // must hold the suit
            if (in_array(14, $ranks, true)) continue;   // we already hold that Ace
            $score = count($ranks) * 10
                   + (in_array(13, $ranks, true) ? 5 : 0)
                   + array_sum($ranks) / 100;
            if ($score > $bestScore) { $bestScore = $score; $best = $sv; }
        }
        return $best;
    }
}

/* ===================== 2-v-2 PLAYOUT ===================== */
final class RikPlay {
    /** @return int tricks won by the declaring side */
    public static function play(array $hands, int $declarer, int $partner,
                                Suit $trump, int $calledAceSuit, int $leader): int {
        $side = [$declarer => true, $partner => true];
        $sideTricks = 0; $lead = $leader;

        for ($t = 0; $t < 13; $t++) {
            $ledSuit = null; $bestSeat = -1; $bestCard = null;
            for ($s = 0; $s < 4; $s++) {
                $seat = ($lead + $s) % 4;
                $card = self::pick($hands[$seat], $ledSuit, $trump,
                                   $bestSeat, $bestCard, $seat, $side, $calledAceSuit);
                self::remove($hands[$seat], $card);
                $ledSuit ??= $card->suit;
                if ($bestCard === null || cardBeats($card, $bestCard, $ledSuit, $trump)) {
                    $bestCard = $card; $bestSeat = $seat;
                }
            }
            if (isset($side[$bestSeat])) $sideTricks++;
            $lead = $bestSeat;
        }
        return $sideTricks;
    }

    private static function pick(array $hand, ?Suit $ledSuit, Suit $trump,
                                 int $bestSeat, ?Card $bestCard,
                                 int $seat, array $side, int $calledAceSuit): Card {
        $legal = self::legal($hand, $ledSuit);

        // Forced called-Ace: if its suit is led and I hold that Ace, I must play it.
        if ($ledSuit !== null && $ledSuit->value === $calledAceSuit) {
            foreach ($legal as $c) if ($c->suit->value === $calledAceSuit && $c->rank === 14) return $c;
        }

        if ($ledSuit === null) {                         // I'm leading
            $iAmSide = isset($side[$seat]);
            $trumps  = array_values(array_filter($legal, fn(Card $c)=>$c->suit===$trump));
            if ($iAmSide && $trumps) return self::max($trumps, $trump);   // draw trumps
            $non = array_values(array_filter($legal, fn(Card $c)=>$c->suit!==$trump));
            if ($iAmSide) return $non ? self::max($non,$trump) : self::max($legal,$trump);
            // defender: attack with highest side card, else lowest trump
            return $non ? self::max($non,$trump) : self::min($legal,$trump);
        }

        $teammateWinning = ($bestSeat >= 0) && (isset($side[$seat]) === isset($side[$bestSeat]));
        $winners = array_values(array_filter(
            $legal, fn(Card $c)=>cardBeats($c,$bestCard,$ledSuit,$trump)));

        if ($teammateWinning) return self::min($legal,$trump);          // preserve
        if ($winners)         return self::min($winners,$trump);        // win cheaply
        return self::min($legal,$trump);                                // discard low
    }

    private static function legal(array $hand, ?Suit $ledSuit): array {
        if ($ledSuit === null) return $hand;
        $same = array_values(array_filter($hand, fn(Card $c)=>$c->suit===$ledSuit));
        return $same ?: $hand;
    }
    private static function max(array $cs, Suit $tr): Card {
        usort($cs, fn(Card $a,Card $b)=>self::k($b,$tr)<=>self::k($a,$tr)); return $cs[0];
    }
    private static function min(array $cs, Suit $tr): Card {
        usort($cs, fn(Card $a,Card $b)=>self::k($a,$tr)<=>self::k($b,$tr)); return $cs[0];
    }
    private static function k(Card $c, Suit $tr): int { return ($c->suit===$tr?100:0)+$c->rank; }
    private static function remove(array &$h, Card $card): void {
        foreach ($h as $i=>$c) if ($c===$card){ array_splice($h,$i,1); return; }
    }
}

/* ===================== HARNESS ===================== */
$numHands = (int)   ($argv[1] ?? 30000);
$RIK_MIN  = (float) ($argv[2] ?? 5.0);     // threshold under test for distribution
$SOLO8_MIN = 8.5;                          // locked from previous calibration

$buckets = [];                             // floor(ownPredicted) => [n,sideSum,made8]
$samples = [];                             // [ownPredicted, sideTricks] for sweep
$bidCounts = ['PASS'=>0,'RIK'=>0,'SOLO8'=>0,'TROELA'=>0];
$partnerLift = ['sum'=>0,'n'=>0];

$deck = Deck::fresh(); $deck->shuffle(); $dealer = 0;

for ($h = 0; $h < $numHands; $h++) {
    $hands = $deck->deal();
    $troela = false;

    foreach ($hands as $seat => $hand) {
        $aces = HandEvaluator::countAces($hand);
        if ($aces >= 3) { $troela = true; }

        // ---- cleaned bid distribution ----
        $bt = HandEvaluator::bestTrump($hand);
        if ($aces >= 3)                          $bidCounts['TROELA']++;
        elseif ($bt['tricks'] >= $SOLO8_MIN)     $bidCounts['SOLO8']++;
        elseif ($bt['tricks'] >= $RIK_MIN
                && RikChoice::calledAceSuit($hand,$bt['suit']) !== null)
                                                 $bidCounts['RIK']++;
        else                                     $bidCounts['PASS']++;

        // ---- Rik calibration (only hands that could legally bid Rik) ----
        if ($aces >= 3) continue;                          // Troela path, not Rik
        $aceSuit = RikChoice::calledAceSuit($hand, $bt['suit']);
        if ($aceSuit === null) continue;                   // can't call -> can't Rik

        // find partner = holder of the called Ace
        $partner = -1;
        foreach ($hands as $ps => $ph) {
            if ($ps === $seat) continue;
            foreach ($ph as $c) if ($c->suit->value===$aceSuit && $c->rank===14){$partner=$ps;break;}
            if ($partner >= 0) break;
        }
        if ($partner < 0) continue;                        // shouldn't happen

        $sideTricks = RikPlay::play(
            [$hands[0],$hands[1],$hands[2],$hands[3]],
            $seat, $partner, $bt['suit'], $aceSuit, ($seat+1)%4);

        $b = (int) floor($bt['tricks']);
        $buckets[$b] ??= ['n'=>0,'sum'=>0,'made'=>0];
        $buckets[$b]['n']++; $buckets[$b]['sum']+=$sideTricks;
        if ($sideTricks >= 8) $buckets[$b]['made']++;
        $samples[] = [$bt['tricks'], $sideTricks];
        $partnerLift['sum'] += $sideTricks - $bt['tricks']; $partnerLift['n']++;
    }

    $trump = Suit::from(random_int(0,3));
    $piles = PlaySim::play([$hands[0],$hands[1],$hands[2],$hands[3]], $trump, ($dealer+1)%4);
    if ($troela) { $deck = Deck::fresh(); $deck->shuffle(); }
    else { $deck->cards = Gather::cut(Gather::gather($piles, 0.15)); }
    $dealer = ($dealer+1)%4;
}

/* ---------------- REPORT ---------------- */
echo "\n=== RIK calibration: declarer OWN predicted vs SIDE result ({$numHands} hands) ===\n";
echo "own pred | n      | mean SIDE tricks | P(side >= 8)\n";
ksort($buckets);
foreach ($buckets as $b=>$d) {
    if ($d['n']<30) continue;
    printf("   %2d    | %6d | %6.2f           | %5.1f%%\n",
        $b, $d['n'], $d['sum']/$d['n'], 100*$d['made']/$d['n']);
}
printf("\nMean partner lift (side tricks - own predicted): +%.2f tricks\n",
    $partnerLift['sum']/max(1,$partnerLift['n']));

echo "\n=== RIK_MIN threshold sweep (pick where P(make 8) is comfortable) ===\n";
echo "threshold | bid-Rik rate | P(make 8 | bid Rik)\n";
foreach ([4.0,4.5,5.0,5.5,6.0,6.5] as $T) {
    $bid=0;$made=0;
    foreach ($samples as [$pred,$st]) if ($pred>=$T){$bid++; if($st>=8)$made++;}
    printf("   %.1f    | %11.1f%% | %5.1f%%\n",
        $T, 100*$bid/max(1,count($samples)), $made? 100*$made/$bid : 0.0);
}

echo "\n=== Cleaned bid distribution (RIK_MIN={$RIK_MIN}, SOLO8_MIN={$SOLO8_MIN}, Misère removed) ===\n";
$tot=array_sum($bidCounts);
foreach ($bidCounts as $k=>$v) printf("  %-7s %5.1f%%\n",$k,100*$v/$tot);
echo "\n";