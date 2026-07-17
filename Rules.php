<?php
declare(strict_types=1);
require_once __DIR__ . '/Contracts.php';

/**
 * House rules. Plain value object — no DB/web dependency, so the sim
 * harness can construct one directly (mirrors the shared-engine model).
 * An unset/empty config reproduces the canonical A1 defaults exactly.
 */
final class Rules {
    private array $cfg;

    public function __construct(array $cfg = []) { $this->cfg = $cfg; }

    /** Build from the games.rules_config JSON (null-safe). */
    public static function fromJson(?string $json): self {
        if ($json === null || $json === '') return new self();
        $d = json_decode($json, true);
        return new self(is_array($d) ? $d : []);
    }

    /* ---- Scoring (fixed ladder, custom payouts) ---- */
    // Falls back to the canonical Contracts:: values when not overridden.
    public function base(string $contract): int {
        return (int)($this->cfg['scoring'][$contract]['base'] ?? Contracts::base($contract));
    }
    public function per(string $contract): int {
        return (int)($this->cfg['scoring'][$contract]['per'] ?? Contracts::per($contract));
    }
    public function kapotBonus(): int {
        return (int)($this->cfg['kapotBonus'] ?? 35);
    }
    public function schoppenPenalty(): int {
        return (int)($this->cfg['schoppenPenalty'] ?? 15);
    }

    /* ---- Spade-lock (Schoppen Mie only) ---- */
    // spadeLead: until_queen | first_trick | first_three | until_any_spade
    public function spadeLead(): string {
        return (string)($this->cfg['spadeLead'] ?? 'until_queen');
    }
    // queenDiscard: forced | anytime
    public function queenDiscard(): string {
        return (string)($this->cfg['queenDiscard'] ?? 'forced');
    }
}
