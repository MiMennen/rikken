<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';
$N=(int)($argv[1]??200); $pdo=DB::conn();
$tally=[]; $err=0; $zs=0; $om=['n'=>0,'m'=>0]; $op=['n'=>0,'m'=>0];
for($i=0;$i<$N;$i++){
    $gid=GameService::create([['type'=>'bot','name'=>'N'],['type'=>'bot','name'=>'O'],
        ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W']]);
    GameService::start($gid);
    try{ for($k=0;$k<3;$k++){ GameRunner::advance($gid);
        $g=$pdo->query("SELECT status,current_hand_id FROM games WHERE id=$gid")->fetch();
        if($g['status']==='hand_scoring'){
            $hd=$pdo->query("SELECT contract,result_summary FROM hands WHERE id={$g['current_hand_id']}")->fetch();
            $tally[$hd['contract']]=($tally[$hd['contract']]??0)+1;
            if($hd['result_summary']){$r=json_decode($hd['result_summary'],true);
                if($hd['contract']==='open_misere'){$om['n']++;if($r['made'])$om['m']++;}
                if($hd['contract']==='open_piek'){$op['n']++;if($r['made'])$op['m']++;}}
            $sum=(int)$pdo->query("SELECT COALESCE(SUM(score),0) FROM game_seats WHERE game_id=$gid")->fetchColumn();
            if($sum!==0)$zs++;
            GameService::nextHand($gid);
        }}
    }catch(\Throwable $e){echo "ERR $gid: ".$e->getMessage()."\n";$err++;}
    $pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);
}
echo "\n=== T3d over $N games ===\n"; ksort($tally);
foreach($tally as $k=>$v)printf("  %-22s %3d\n",$k,$v);
printf("Open Misère: %d made %d | Open Piek: %d made %d\n",$om['n'],$om['m'],$op['n'],$op['m']);
echo "errors: $err, zero-sum failures: $zs -> ".(($err+$zs)===0?"ALL GOOD ✅":"❌")."\n";