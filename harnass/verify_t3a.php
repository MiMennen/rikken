<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';

$N=(int)($argv[1]??300); $pdo=DB::conn();
$tally=[]; $errors=0; $scoreErr=0;

for($i=0;$i<$N;$i++){
    $gid=GameService::create([['type'=>'bot','name'=>'N'],['type'=>'bot','name'=>'O'],
        ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W']]);
    GameService::start($gid);
    try{
        GameRunner::advance($gid);
        // play a couple of hands to exercise scoring of any solos
        for($k=0;$k<2;$k++){ $st=$pdo->query("SELECT status FROM games WHERE id=$gid")->fetchColumn();
            if($st==='hand_scoring'){ GameService::nextHand($gid); GameRunner::advance($gid);} }
    }catch(\Throwable $e){ echo "ERR $gid: ".$e->getMessage()."\n"; $errors++; }

    $c=$pdo->query("SELECT contract FROM hands WHERE id=(SELECT current_hand_id FROM games WHERE id=$gid)")->fetchColumn();
    $tally[$c]=($tally[$c]??0)+1;
    // zero-sum check on any scored hand
    $sum=(int)$pdo->query("SELECT COALESCE(SUM(score),0) FROM game_seats WHERE game_id=$gid")->fetchColumn();
    if($sum!==0){ echo "ZERO-SUM FAIL $gid: $sum\n"; $scoreErr++; }
    $pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);
}
echo "\n=== T3a over $N games ===\n";
ksort($tally); foreach($tally as $k=>$v) printf("  %-20s %4d\n",$k,$v);
echo "play errors: $errors, zero-sum failures: $scoreErr -> ".(($errors+$scoreErr)===0?"ALL GOOD ✅":"❌")."\n";