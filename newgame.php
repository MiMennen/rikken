<?php
declare(strict_types=1);
header('Content-Type: application/json');
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';
require_once __DIR__ . '/Contracts.php';   // needed for Contracts::isBiddable in payout validation

try {
    $in   = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $name = trim((string)($in['name'] ?? 'You'));
    if ($name === '') $name = 'You';
    $name = mb_substr($name, 0, 40);

    // ---- game length (A2 fix: read hands/target the client actually sends) ----
    $maxHands    = 8;      // default
    $targetScore = null;
    if (isset($in['hands']))       $maxHands = max(1, min(24, (int)$in['hands']));
    elseif (isset($in['target']))  { $maxHands = null; $targetScore = max(1, min(500, (int)$in['target'])); }

    // ---- house rules (Tier-1: custom scoring + spade-lock) ----
    $rules = [];

    // Spade-lock variants (whitelist — reject anything unexpected)
    $lead = $in['spadeLead'] ?? 'until_queen';
    if (in_array($lead, ['until_queen','first_trick','first_three','until_any_spade'], true))
        $rules['spadeLead'] = $lead;
    $qd = $in['queenDiscard'] ?? 'forced';
    if (in_array($qd, ['forced','anytime'], true))
        $rules['queenDiscard'] = $qd;

    // Custom payouts (optional). Clamp to sane ranges; keep Schoppen divisible by 3.
    if (!empty($in['scoring']) && is_array($in['scoring'])) {
        foreach ($in['scoring'] as $k => $v) {
            if (!Contracts::isBiddable($k) && $k !== 'troela') continue;
            $b = isset($v['base']) ? max(0, min(200, (int)$v['base'])) : null;
            $p = isset($v['per'])  ? max(0, min(50,  (int)$v['per']))  : null;
            if ($b !== null) $rules['scoring'][$k]['base'] = $b;
            if ($p !== null) $rules['scoring'][$k]['per']  = $p;
        }
    }
    if (isset($in['kapotBonus'])) $rules['kapotBonus'] = max(0, min(200, (int)$in['kapotBonus']));
    if (isset($in['schoppenPenalty'])) {
        $sp = max(3, min(60, (int)$in['schoppenPenalty']));
        $rules['schoppenPenalty'] = $sp - ($sp % 3);   // force divisible by 3 → zero-sum safe
    }

    $rulesJson = $rules ? json_encode($rules, JSON_THROW_ON_ERROR) : null;

    $gid = GameService::create([
        ['type'=>'human','name'=>$name],
        ['type'=>'bot','name'=>'Oost'],
        ['type'=>'bot','name'=>'Zuid'],
        ['type'=>'bot','name'=>'West'],
    ], $maxHands, $targetScore, $rulesJson);   // ← now passes length AND rules

    GameService::start($gid);
    GameRunner::advance($gid);            // bots act up to the human's first turn

    $token = GameService::seatToken($gid, 0);
    echo json_encode(['url'=>"index.html?token=$token"], JSON_THROW_ON_ERROR);
} 
catch (\Throwable $e) {
    error_log('newgame.php error: ' . $e->getMessage());   // full detail → server log (private)
    http_response_code(400);
    echo json_encode(['error' => 'Could not create game. Please try again.']);
}}
