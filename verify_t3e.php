<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';
require_once __DIR__ . '/Contracts.php';

$pdo=DB::conn();

// 1. Sanity: the full ladder is now biddable and ranks are strictly ordered.
$biddable=[]; foreach(Contracts::DEF as $name=>$d) if(!empty($d['biddable'])) $biddable[$name]=$d['rank'];
asort($biddable);
echo "Biddable ladder (low->high):\n";
foreach($biddable as $n=>$r) printf("  %-22s rank %d  base %d\n",$n,$r,Contracts::base($n));
$ranks=array_values($biddable);
$strictly = $ranks===array_values(array_unique($ranks)) && $ranks===(function($a){sort($a);return $a;})($ranks);
echo "ranks strictly increasing & unique: ".($strictly?"✅":"❌")."\n\n";

// 2. Live run: does praatje ever fire, and does everything stay clean/zero-sum?
$N=(int)($argv[1]??250); $tally=[]; $err=0; $zs=0; $pr=['n'=>0,'m'=>0];
for($i=0;$i<$N;$i++){
    $gid=GameService::create([['type'=>'bot','name'=>'N'],['type'=>'bot','name'=>'O'],
        ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W']]);
    GameService::start($gid);
    try{ for($k=0;$k<3;$k++){ GameRunner::advance($gid);
        $g=$pdo->query("SELECT status,current_hand_id FROM games WHERE id=$gid")->fetch();
        if($g['status']==='hand_scoring'){
            $hd=$pdo->query("SELECT contract,result_summary FROM hands WHERE id={$g['current_hand_id']}")->fetch();
            $tally[$hd['contract']]=($tally[$hd['contract']]??0)+1;
            if($hd['contract']==='open_misere_praatje' && $hd['result_summary']){
                $r=json_decode($hd['result_summary'],true); $pr['n']++; if($r['made'])$pr['m']++; }
            $sum=(int)$pdo->query("SELECT COALESCE(SUM(score),0) FROM game_seats WHERE game_id=$gid")->fetchColumn();
            if($sum!==0)$zs++;
            GameService::nextHand($gid);
        }}
    }catch(\Throwable $e){echo "ERR $gid: ".$e->getMessage()."\n";$err++;}
    $pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);
}
echo "=== T3e over $N games ===\n"; ksort($tally);
foreach($tally as $k=>$v) printf("  %-22s %3d\n",$k,$v);
printf("praatje: bid %d, made %d\n",$pr['n'],$pr['m']);
echo "errors: $err, zero-sum failures: $zs -> ".(($err+$zs)===0?"ALL GOOD ✅":"❌")."\n";