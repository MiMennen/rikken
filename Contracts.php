<?php
declare(strict_types=1);

/**
 * Central registry of every contract: ladder rank, trick target, whether it has
 * a partner, whether it uses trump, scoring, and whether bots may bid it yet.
 * Adding a contract later = add/flip a row here, not scattered edits.
 */
final class Contracts {

    // rank = bidding ladder order (higher beats lower). Full ladder defined now.
    // target: tricks needed (>=) for solos/rik; misere/piek use 'exact'.
    // type: 'partner' (rik/troela) | 'solo' | 'avoid'
    // biddable: may a player/bot OPEN this bid in v-now? (enable as validated)
    public const DEF = [
        'rik'                 => ['rank'=>10, 'target'=>8,  'type'=>'partner','trump'=>true,  'base'=>10,'per'=>5,'biddable'=>true],
        'rik_beter'           => ['rank'=>20, 'target'=>8,  'type'=>'partner','trump'=>'hearts','base'=>10,'per'=>5,'biddable'=>true],
        'solo8'               => ['rank'=>30, 'target'=>8,  'type'=>'solo',   'trump'=>true,  'base'=>10,'per'=>5,'biddable'=>true],
        'piek'                => ['rank'=>40, 'target'=>'1','type'=>'avoid',  'trump'=>false, 'base'=>15,'per'=>0,'biddable'=>true],
        'solo9'               => ['rank'=>50, 'target'=>9,  'type'=>'solo',   'trump'=>true,  'base'=>20,'per'=>5,'biddable'=>true],
        'misere'              => ['rank'=>60, 'target'=>'0','type'=>'avoid',  'trump'=>false, 'base'=>30,'per'=>0,'biddable'=>true],
        'solo10'              => ['rank'=>70, 'target'=>10, 'type'=>'solo',   'trump'=>true,  'base'=>30,'per'=>5,'biddable'=>true],
		'open_piek'           => ['rank'=>80, 'target'=>'1','type'=>'avoid','trump'=>false,'base'=>40,'per'=>0,'biddable'=>true,'open'=>true],
		'solo11'              => ['rank'=>90, 'target'=>11, 'type'=>'solo',   'trump'=>true,  'base'=>40,'per'=>5,'biddable'=>true],
        'open_misere'         => ['rank'=>100,'target'=>'0','type'=>'avoid','trump'=>false,'base'=>50,'per'=>0,'biddable'=>true,'open'=>true],
        'open_misere_praatje' => ['rank'=>110,'target'=>'0','type'=>'avoid','trump'=>false,'base'=>65,'per'=>0,'biddable'=>true,'open'=>true,'praatje'=>true],
        'solo12'              => ['rank'=>120,'target'=>12, 'type'=>'solo',   'trump'=>true,  'base'=>55,'per'=>5,'biddable'=>true],
        'solo13'              => ['rank'=>130,'target'=>13, 'type'=>'solo',   'trump'=>true,  'base'=>70,'per'=>5,'biddable'=>true],
        // troela is forced (not bid); scored like rik
        'troela'              => ['rank'=>10, 'target'=>8,  'type'=>'partner','trump'=>true,  'base'=>10,'per'=>5,'biddable'=>false],
		// Pass-game outcome (not biddable). Scored specially in ScoringService.
        'pass_schoppenmie' => ['rank'=>0, 'target'=>0, 'type'=>'schoppenmie', 'trump'=>false,
                               'base'=>0, 'per'=>0, 'biddable'=>false],
    ];

   public static function type(string $c): string { return self::DEF[$c]['type'] ?? 'none'; }
    public static function target(string $c)        { return self::DEF[$c]['target'] ?? 0; }
    public static function base(string $c): int      { return self::DEF[$c]['base'] ?? 0; }
    public static function per(string $c): int       { return self::DEF[$c]['per'] ?? 0; }
    public static function rank(string $c): int      { return self::DEF[$c]['rank'] ?? 0; }
    public static function isBiddable(string $c): bool { return !empty(self::DEF[$c]['biddable']); }
    public static function usesTrump(string $c): bool  { return self::DEF[$c]['trump'] !== false; }
    public static function forcedHearts(string $c): bool { return (self::DEF[$c]['trump'] ?? null) === 'hearts'; }
	public static function isOpen(string $c): bool { return !empty(self::DEF[$c]['open']); }
	public static function isPraatje(string $c): bool { return !empty(self::DEF[$c]['praatje']); }


    /** Biddable contracts a seat may OPEN or raise to, given the current highest rank. */
    public static function biddableAbove(int $currentRank): array {
        $out = [];
        foreach (self::DEF as $name => $d)
            if (!empty($d['biddable']) && $d['rank'] > $currentRank) $out[] = $name;
        return $out;
    }
}