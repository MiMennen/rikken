<?php
declare(strict_types=1);
if (defined('RIKKEN_ENGINE_LOADED')) return;
define('RIKKEN_ENGINE_LOADED', true);

/**
 * Rikken — shared engine. CLASSES ONLY. No executing/top-level code.
 * require'd by rikken_sim.php, rikken_bidding.php, rikken_rik.php and (later)
 * the live web game. Rules are written ONCE, here.
 */

/* ===================== CORE TYPES ===================== */
enum Suit: int {
    case Clubs = 0; case Diamonds = 1; case Hearts = 2; case Spades = 3;
    public function symbol(): string {
        return match ($this) {
            Suit::Clubs => '♣', Suit::Diamonds => '♦',
            Suit::Hearts => '♥', Suit::Spades => '♠',
        };
    }
}

final class Card {
    public function __construct(
        public readonly Suit $suit,
        public readonly int $rank   // 2..14 (14 = Ace)
    ) {}
    public function isAce(): bool { return $this->rank === 14; }
    public function __toString(): string {
        $r = match ($this->rank) {
            14 => 'A', 13 => 'K', 12 => 'Q', 11 => 'J', 10 => 'T',
            default => (string) $this->rank,
        };
        return $r . $this->suit->symbol();
    }
	// --- add inside final class Card ---

    /** 0..51  ->  Card.  (suit = id DIV 13, rank = id MOD 13 + 2) */
    public static function fromId(int $id): self {
        return new self(Suit::from(intdiv($id, 13)), ($id % 13) + 2);
    }

    /** Card -> 0..51 */
    public function id(): int {
        return $this->suit->value * 13 + ($this->rank - 2);
    }
}

final class Deck {
    /** @var Card[] persistent ordered 52-card stack (lives across hands) */
    public array $cards = [];

    public static function fresh(): self {
        $d = new self();
        foreach (Suit::cases() as $suit)
            for ($r = 2; $r <= 14; $r++) $d->cards[] = new Card($suit, $r);
        return $d;
    }
    public function shuffle(): void {
        for ($i = count($this->cards) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$this->cards[$i], $this->cards[$j]] = [$this->cards[$j], $this->cards[$i]];
        }
    }
    /** Deal 4-5-4 in blocks, clockwise. @return Card[][] seats 0..3 */
    public function deal(): array {
        $hands = [[], [], [], []]; $idx = 0;
        foreach ([4, 5, 4] as $block)
            for ($seat = 0; $seat < 4; $seat++)
                for ($k = 0; $k < $block; $k++) $hands[$seat][] = $this->cards[$idx++];
        return $hands;
    }
}

/* ===================== TRICK COMPARISON ===================== */
function cardBeats(Card $c, Card $best, Suit $ledSuit, ?Suit $trump): bool {
    $cT = $trump !== null && $c->suit === $trump;
    $bT = $trump !== null && $best->suit === $trump;
    if ($cT !== $bT) return $cT;
    if ($cT && $bT)  return $c->rank > $best->rank;
    if ($c->suit === $ledSuit && $best->suit !== $ledSuit) return true;
    if ($c->suit !== $ledSuit) return false;
    return $c->rank > $best->rank;
}

/* ===================== LEGAL-BUT-DUMB PLAY (for deck stack realism) ===================== */
final class PlaySim {
    /** @return Card[][] winnerPiles grouped by seat 0..3 */
    public static function play(array $hands, ?Suit $trump, int $leader): array {
        $winnerPiles = [[], [], [], []]; $lead = $leader;
        for ($t = 0; $t < 13; $t++) {
            $trick = []; $ledSuit = null;
            for ($s = 0; $s < 4; $s++) {
                $seat = ($lead + $s) % 4;
                $card = self::choose($hands[$seat], $ledSuit);
                self::remove($hands[$seat], $card);
                $ledSuit ??= $card->suit; $trick[$seat] = $card;
            }
            $winner = self::winner($trick, $ledSuit, $trump, $lead);
            for ($s = 0; $s < 4; $s++) $winnerPiles[$winner][] = $trick[($lead + $s) % 4];
            $lead = $winner;
        }
        return $winnerPiles;
    }
    private static function choose(array $hand, ?Suit $ledSuit): Card {
        if ($ledSuit !== null) {
            $same = array_values(array_filter($hand, fn(Card $c) => $c->suit === $ledSuit));
            if ($same) return $same[random_int(0, count($same) - 1)];
        }
        return $hand[random_int(0, count($hand) - 1)];
    }
    private static function remove(array &$hand, Card $card): void {
        foreach ($hand as $i => $c) if ($c === $card) { array_splice($hand, $i, 1); return; }
    }
    private static function winner(array $trick, Suit $ledSuit, ?Suit $trump, int $lead): int {
        $bestSeat = $lead; $best = $trick[$lead];
        foreach ($trick as $seat => $card)
            if (cardBeats($card, $best, $ledSuit, $trump)) { $best = $card; $bestSeat = $seat; }
        return $bestSeat;
    }
}

/* ===================== GATHER / CUT (validated deck engine) ===================== */
final class Gather {
    /** Per-player piles (decision 1a) + imperfection swaps. */
    public static function gather(array $winnerPiles, float $imperfection): array {
        $stack = [];
        for ($seat = 0; $seat < 4; $seat++) foreach ($winnerPiles[$seat] as $c) $stack[] = $c;
        $n = count($stack); $swaps = (int) round($imperfection * $n);
        for ($k = 0; $k < $swaps; $k++) {
            $i = random_int(0, $n - 2);
            [$stack[$i], $stack[$i + 1]] = [$stack[$i + 1], $stack[$i]];
        }
        return $stack;
    }
    /** Single biased cut ("heffen"). */
    public static function cut(array $stack): array {
        $n = count($stack);
        $cut = (int) round($n / 2 + self::gauss() * ($n / 8));
        $cut = max(1, min($n - 1, $cut));
        return array_merge(array_slice($stack, $cut), array_slice($stack, 0, $cut));
    }
    private static function gauss(): float {
        $u1 = max(mt_rand() / mt_getrandmax(), 1e-9); $u2 = mt_rand() / mt_getrandmax();
        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }
}

/* ===================== METRICS ===================== */
final class Metrics {
    public static function handStats(array $hand): array {
        $bySuit = [0, 0, 0, 0]; $aces = 0;
        foreach ($hand as $c) { $bySuit[$c->suit->value]++; if ($c->isAce()) $aces++; }
        rsort($bySuit);
        return ['longest'=>$bySuit[0], 'aces'=>$aces,
                'voids'=>count(array_filter($bySuit, fn($x)=>$x===0))];
    }
}

/* ===================== HAND EVALUATOR (validated + Step-5 helpers) ===================== */
final class HandEvaluator {

    /** @return int[][] ranks grouped by suit value 0..3, each sorted DESC */
    public static function bySuit(array $hand): array {
        $g = [0=>[],1=>[],2=>[],3=>[]];
        foreach ($hand as $c) $g[$c->suit->value][] = $c->rank;
        foreach ($g as &$r) rsort($r);
        return $g;
    }

    public static function countAces(array $hand): int {
        $n = 0; foreach ($hand as $c) if ($c->rank === 14) $n++; return $n;
    }

    /** Expected own tricks if $trump is trump. (LOCKED weights — calibrated.) */
    public static function trumpEval(array $hand, Suit $trump): float {
        $g = self::bySuit($hand);
        $tr = $g[$trump->value]; $t = self::trumpSuitTricks($tr);
        foreach ([0,1,2,3] as $sv) { if ($sv===$trump->value) continue; $t += self::sideSuitTricks($g[$sv]); }
        $spare = max(0, count($tr) - 3); $short = 0;
        foreach ([0,1,2,3] as $sv) {
            if ($sv === $trump->value) continue;
            $l = count($g[$sv]);
            if ($l === 0) $short += 2; elseif ($l === 1) $short += 1;
        }
        return $t + min($spare, $short) * 0.5;
    }

    private static function trumpSuitTricks(array $desc): float {
        $len = count($desc); if ($len === 0) return 0.0;
        $has = fn(int $r)=>in_array($r,$desc,true); $t = 0.0;
        if ($has(14)) $t += 1.0; if ($has(13)) $t += 0.85;
        if ($has(12)) $t += 0.6; if ($has(11)) $t += 0.35;
        if ($len >= 4) $t += ($len - 3) * 0.7;
        return min($t, (float) $len);
    }

    private static function sideSuitTricks(array $desc): float {
        $len = count($desc); if ($len === 0) return 0.0;
        $has = fn(int $r)=>in_array($r,$desc,true); $t = 0.0;
        if ($has(14)) $t += 1.0;
        if ($has(13)) $t += $len >= 2 ? 0.8 : 0.45;
        if ($has(12)) $t += $len >= 3 ? 0.45 : ($len === 2 ? 0.25 : 0.1);
        if ($len >= 5) $t += ($len - 4) * 0.5;
        return $t;
    }

    /** Best trump suit (optionally excluding one suit value). */
    public static function bestTrump(array $hand, ?int $excludeSuitValue = null): array {
        $best = null; $bestT = -1.0;
        foreach (Suit::cases() as $s) {
            if ($excludeSuitValue !== null && $s->value === $excludeSuitValue) continue;
            $e = self::trumpEval($hand, $s);
            if ($e > $bestT) { $bestT = $e; $best = $s; }
        }
        return ['suit' => $best, 'tricks' => $bestT];
    }

    /** Is there a non-trump suit we hold but lack the Ace of? (optionally exclude a suit) */
    public static function canCallAce(array $hand, ?int $excludeSuitValue = null): bool {
        $g = self::bySuit($hand);
        foreach ([0,1,2,3] as $sv) {
            if ($excludeSuitValue !== null && $sv === $excludeSuitValue) continue;
            if (count($g[$sv]) >= 1 && !in_array(14, $g[$sv], true)) return true;
        }
        return false;
    }

    /** Best non-trump suit we HOLD but LACK the Ace of (longest; kicker = King). */
    public static function bestCalledAceSuit(array $hand, int $trumpValue): ?int {
        $g = self::bySuit($hand); $best = null; $bestScore = -1.0;
        foreach ([0,1,2,3] as $sv) {
            if ($sv === $trumpValue) continue;
            $r = $g[$sv];
            if (count($r) < 1 || in_array(14, $r, true)) continue;
            $score = count($r) * 10 + (in_array(13, $r, true) ? 5 : 0) + array_sum($r) / 100;
            if ($score > $bestScore) { $bestScore = $score; $best = $sv; }
        }
        return $best;
    }

    /** Best suit we HOLD but LACK the King of (4-ace Troela: call a King). */
    public static function bestCalledKingSuit(array $hand): ?int {
        $g = self::bySuit($hand); $best = null; $bestScore = -1.0;
        foreach ([0,1,2,3] as $sv) {
            $r = $g[$sv];
            if (count($r) < 1 || in_array(13, $r, true)) continue;
            $score = count($r) * 10 + array_sum($r) / 100;
            if ($score > $bestScore) { $bestScore = $score; $best = $sv; }
        }
        return $best;
    }

    /** Suit values (0..3) in which the hand holds an Ace. */
    public static function aceSuits(array $hand): array {
        $out = []; foreach ($hand as $c) if ($c->rank === 14) $out[] = $c->suit->value;
        return $out;
    }
}

/** @param Card[] $cards @return int[] */
function cards_to_ids(array $cards): array {
    return array_map(fn(Card $c) => $c->id(), $cards);
}
/** @param int[] $ids @return Card[] */
function ids_to_cards(array $ids): array {
    return array_map(fn(int $id) => Card::fromId($id), $ids);
}