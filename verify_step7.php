<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';
require_once __DIR__ . '/ScoringService.php';

$HANDS = (int)($argv[1] ?? 12);
$pdo = DB::conn();

$gid = GameService::create([
    ['type'=>'bot','name'=>'Noord'],['type'=>'bot','name'=>'Oost'],
    ['type'=>'bot','name'=>'Zuid'],['type'=>'bot','name'=>'West'],
]);
GameService::start($gid);

$errors = 0;
for ($hand = 1; $hand <= $HANDS; $hand++) {
    GameRunner::advance($gid);                 // play to hand_scoring
    $summary = ScoringService::scoreHand($gid);// (idempotent if already scored by runner)

    $scores = $pdo->query("SELECT seat, score FROM game_seats WHERE game_id=$gid ORDER BY seat")->fetchAll();
    $tot = array_sum(array_map(fn($r)=>(int)$r['score'], $scores));
    if ($tot !== 0) { echo "ERR hand $hand: scores sum to $tot (not zero!)\n"; $errors++; }

    $c = $summary['contract'];
    $detail = $c==='pass_schoppenmie'
        ? "♠Q→s{$summary['queenSeat']} last→s{$summary['lastTrickSeat']}"
        : "decl s{$summary['declarer']} ".($summary['partner']!==null?"+s{$summary['partner']} ":"")
          ."side={$summary['sideTricks']}/{$summary['target']} ".($summary['made']?'MADE':'failed')." M={$summary['magnitude']}";
    $sc = implode(' ', array_map(fn($r)=>"s{$r['seat']}:".sprintf('%+d',$r['score']), $scores));
    printf("hand %2d | %-16s %-46s | %s\n", $hand, $c, $detail, $sc);

    $next = GameService::nextHand($gid);
    if ($next['status'] === 'finished') { echo "Game finished early.\n"; break; }
}

echo "\nZero-sum violations: $errors -> " . ($errors===0 ? "ALL GOOD ✅" : "❌") . "\n";
$pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);
echo "Cleaned up game $gid\n";