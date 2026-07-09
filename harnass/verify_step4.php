<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';

$N = (int) ($argv[1] ?? 200);
$tally = ['troela'=>0,'rik'=>0,'rik_beter'=>0,'solo8'=>0,'schoppenmie'=>0];
$pdo = DB::conn();

for ($i = 0; $i < $N; $i++) {
    $gid = GameService::create([
        ['type'=>'bot','name'=>'N'],['type'=>'bot','name'=>'O'],
        ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W'],
    ]);
    GameService::start($gid);
    GameRunner::advance($gid);   // bots bid to completion

    $row = $pdo->query("SELECT g.status, h.contract
                          FROM games g JOIN hands h ON h.id=g.current_hand_id
                         WHERE g.id=$gid")->fetch();
    if     ($row['contract'] === 'troela')            $tally['troela']++;
    elseif ($row['contract'] === 'pass_schoppenmie')  $tally['schoppenmie']++;
    elseif (in_array($row['contract'], ['rik','rik_beter','solo8'], true)) $tally[$row['contract']]++;

    // show the first few games' bid logs for eyeballing
    if ($i < 3) {
        $bids = $pdo->query("SELECT seat,bid FROM bids WHERE hand_id=(SELECT current_hand_id FROM games WHERE id=$gid) ORDER BY seq")->fetchAll();
        $log = implode(' ', array_map(fn($b)=>"s{$b['seat']}:{$b['bid']}", $bids));
        echo "game $gid -> {$row['contract']} | bids: " . ($log ?: '(none/troela)') . "\n";
    }
    $pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);
}

echo "\n=== Bidding outcomes over $N games ===\n";
foreach ($tally as $k=>$v) printf("  %-12s %4d  (%4.1f%%)\n", $k, $v, 100*$v/$N);