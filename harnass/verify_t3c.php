<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';

$N=(int)($argv[1]??150); $pdo=DB::conn();
$tally=[]; $errors=0; $zerosum=0; $mis=['n'=>0,'made'=>0]; $pk=['n'=>0,'made'=>0];

for($i=0;$i<$N;$i++){
    $gid=GameService::create([['type'=>'bot','name'=>'N'],['type'=>'bot','name'=>'O'],
        ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W']]);
    GameService::start($gid);
    try{
        for($k=0;$k<3;$k++){
            GameRunner::advance($gid);
            $st=$pdo->query("SELECT status FROM games WHERE id=$gid")->fetchColumn();
            if($st==='hand_scoring'){
                $hd=$pdo->query("SELECT contract,result_summary FROM hands WHERE id=(SELECT current_hand_id FROM games WHERE id=$gid)")->fetch();
                $tally[$hd['contract']]=($tally[$hd['contract']]??0)+1;
                if($hd['result_summary']){ $r=json_decode($hd['result_summary'],true);
                    if($hd['contract']==='misere'){$mis['n']++; if($r['made'])$mis['made']++;}
                    if($hd['contract']==='piek'){$pk['n']++; if($r['made'])$pk['made']++;}
                }
                $sum=(int)$pdo->query("SELECT COALESCE(SUM(score),0) FROM game_seats WHERE game_id=$gid")->fetchColumn();
                if($sum!==0){$zerosum++; echo "ZEROSUM FAIL $gid\n";}
                GameService::nextHand($gid);
            }
        }
    }catch(\Throwable $e){ echo "ERR $gid: ".$e->getMessage()."\n"; $errors++; }
    $pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);
}
echo "\n=== T3c over $N games ===\n";
ksort($tally); foreach($tally as $k=>$v) printf("  %-16s %3d\n",$k,$v);
printf("Misère bid: %d, made %d (%.0f%%)\n",$mis['n'],$mis['made'],$mis['n']?100*$mis['made']/$mis['n']:0);
printf("Piek bid:   %d, made %d (%.0f%%)\n",$pk['n'],$pk['made'],$pk['n']?100*$pk['made']/$pk['n']:0);
echo "errors: $errors, zero-sum failures: $zerosum -> ".(($errors+$zerosum)===0?"ALL GOOD ✅":"❌")."\n";