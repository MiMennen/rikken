<?php
declare(strict_types=1);

/**
 * Rikken — Headless deck engine + Monte Carlo harness (PHP 8.2)
 * Run:  php rikken_sim.php [numHands=10000] [imperfection=0.15]
 *
 * Validates the "simulated no-shuffle tradition":
 *   gather per-player piles -> single biased cut (heffen) -> deal 4-5-4 in blocks
 *   full reshuffle at session start and after any Troela (3+ aces in one hand).
 *
 * 'imperfection' (0.0 .. ~0.6) models sloppy real-world pickup. 0.0 = maximal
 * clumping (degenerate); higher = closer to random. This is the knob we tune.
 */

require __DIR__.'/rikken_engine.php'

/* ----------------------------- HARNESS ----------------------------------- */

$numHands     = (int)   ($argv[1] ?? 10000);
$imperfection = (float) ($argv[2] ?? 0.15);

$longestHist = array_fill(4, 10, 0);  // longest suit length 4..13 (min is 4)
$aceHist     = [0, 0, 0, 0, 0];       // hands (per player) with 0..4 aces
$troelaHands = 0;                     // hands where SOME player has exactly 3 aces
$fourAceHands = 0;
$reshuffles  = 0;
$playerHands = 0;

$deck = Deck::fresh(); $deck->shuffle();
$dealer = 0;

for ($h = 0; $h < $numHands; $h++) {
    $hands = $deck->deal();

    $troela = false; $fourAce = false;
    foreach ($hands as $hand) {
        $st = Metrics::handStats($hand);
        $longestHist[min($st['longest'], 13)]++;
        $aceHist[$st['aces']]++;
        if ($st['aces'] === 3) $troela = true;
        if ($st['aces'] === 4) $fourAce = true;
        $playerHands++;
    }
    if ($troela)  $troelaHands++;
    if ($fourAce) $fourAceHands++;

    // simulate a plausible hand of play to build the next stack
    $trump  = Suit::from(random_int(0, 3));
    $leader = ($dealer + 1) % 4;
    $piles  = PlaySim::play($hands, $trump, $leader);

    if ($troela || $fourAce) {                 // decision 1b: reshuffle on Troela
        $deck = Deck::fresh(); $deck->shuffle(); $reshuffles++;
    } else {
        $deck->cards = Gather::cut(Gather::gather($piles, $imperfection));
    }
    $dealer = ($dealer + 1) % 4;
}

/* ----------------------------- REPORT ------------------------------------ */

function bar(float $pct): string { return str_repeat('█', (int) round($pct / 2)); }

printf("\n=== Rikken deck engine — %d hands, imperfection=%.2f ===\n\n", $numHands, $imperfection);

echo "Longest suit per hand (% of player-hands):\n";
echo "  len   sim%    random%   bar\n";
$randomLongest = [4 => 35.1, 5 => 44.3, 6 => 16.5, 7 => 3.53, 8 => 0.47, 9 => 0.037];
for ($len = 4; $len <= 11; $len++) {
    $pct = 100 * ($longestHist[$len] ?? 0) / $playerHands;
    printf("  %2d   %5.2f   %6.3f    %s\n", $len, $pct, $randomLongest[$len] ?? 0.0, bar($pct));
}

echo "\nAces per hand (% of player-hands):\n";
echo "  aces  sim%    random%\n";
$randomAces = [0 => 30.38, 1 => 43.88, 2 => 21.35, 3 => 4.12, 4 => 0.264];
for ($a = 0; $a <= 4; $a++) {
    printf("  %2d   %5.2f   %6.3f\n", $a, 100 * $aceHist[$a] / $playerHands, $randomAces[$a]);
}

printf("\nTroela rate (a hand where someone holds exactly 3 aces): %.2f%%  (random ≈ 16.5%%)\n",
    100 * $troelaHands / $numHands);
printf("Four-ace ('call a king') rate: %.2f%%  (random ≈ 1.05%%)\n",
    100 * $fourAceHands / $numHands);
printf("Forced reshuffles: %d (%.1f%% of hands)\n",
    $reshuffles, 100 * $reshuffles / $numHands);
echo "\n";