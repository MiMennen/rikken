<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';

$N=(int)($argv[1]??200); $pdo=DB::conn();
$misEarly=0; $misTot=0; $pkEarly=0; $pkTot=0; $deckErr=0; $err=0;

for($i=0;$i<$N;$i++){
    $gid=GameService::create([['type'=>'bot','name'=>'N'],['type'=>'bot','name'=>'O'],
        ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W']]);
    GameService::start($gid);
    try{
        for($k=0;$k<3;$k++){
            GameRunner::advance($gid);
            $g=$pdo->query("SELECT status,current_hand_id FROM games WHERE id=$gid")->fetch();
            if($g['status']==='hand_scoring'){
                $hid=(int)$g['current_hand_id'];
                $hd=$pdo->query("SELECT contract,declarer_seat,result_summary FROM hands WHERE id=$hid")->fetch();
                $tricksPlayed=(int)$pdo->query("SELECT COUNT(*) FROM tricks WHERE hand_id=$hid AND winner_seat IS NOT NULL")->fetchColumn();
                if($hd['contract']==='misere'){ $misTot++;
                    $r=json_decode($hd['result_summary'],true);
                    if(!$r['made'] && $tricksPlayed<13) $misEarly++; }
                if($hd['contract']==='piek'){ $pkTot++;
                    $r=json_decode($hd['result_summary'],true);
                    if(!$r['made'] && $tricksPlayed<13) $pkEarly++; }
                // deck integrity check happens inside nextHand via save()'s 52 assert
                GameService::nextHand($gid);
                $deck=json_decode($pdo->query("SELECT deck_state FROM games WHERE id=$gid")->fetchColumn(),true);
                if(count($deck)!==52 || count(array_unique($deck))!==52){ $deckErr++; echo "DECK BROKEN $gid\n"; }
            }
        }
    }catch(\Throwable $e){ echo "ERR $gid: ".$e->getMessage()."\n"; $err++; }
    $pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);
}
echo "\n=== Early-termination check ($N games) ===\n";
echo "Misère hands: $misTot, ended early on failure: $misEarly\n";
echo "Piek hands:   $pkTot, ended early on failure: $pkEarly\n";
echo "deck integrity failures: $deckErr, errors: $err -> ".(($deckErr+$err)===0?"ALL GOOD ✅":"❌")."\n";