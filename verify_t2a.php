<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';

$N = (int)($argv[1] ?? 400);
$pdo = DB::conn();
$sm=0; $errors=0; $violLead=0; $violDiscard=0; $violForcedQ=0;

for ($i=0;$i<$N;$i++){
    $gid = GameService::create([
        ['type'=>'bot','name'=>'N'],['type'=>'bot','name'=>'O'],
        ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W'],
    ]);
    GameService::start($gid);
    try { GameRunner::advance($gid); } catch(\Throwable $e){ echo "PLAY ERROR $gid: ".$e->getMessage()."\n"; $errors++; }

    $hnd = $pdo->query("SELECT id,contract,dealt_snapshot FROM hands
                        WHERE id=(SELECT current_hand_id FROM games WHERE id=$gid)")->fetch();
    if ($hnd['contract']==='pass_schoppenmie'){
        $sm++;
        $hands = json_decode($hnd['dealt_snapshot'], true);           // [seat=>ids]
        $plays = $pdo->query("SELECT t.led_suit, p.play_seq, p.seat, p.card
            FROM tricks t JOIN trick_plays p ON p.trick_id=t.id
            WHERE t.hand_id={$hnd['id']} ORDER BY t.trick_number, p.play_seq")->fetchAll();

        $qFallen=false;
        foreach ($plays as $pl){
            $seat=(int)$pl['seat']; $card=(int)$pl['card']; $led=(int)$pl['led_suit'];
            $isLead=((int)$pl['play_seq']===0); $suit=intdiv($card,13);
            $hand=$hands[$seat];
            $onlySpades=true; foreach($hand as $id) if(intdiv($id,13)!==3){$onlySpades=false;break;}
            $voidInLed = !in_array($led, array_map(fn($id)=>intdiv($id,13),$hand), true);

            if ($suit===3){
                if ($led===3){ if ($isLead && !($qFallen||$onlySpades)) $violLead++; }
                else { if (!($qFallen||$onlySpades||$card===49)) $violDiscard++; }
            }
            if (!$isLead && $led!==3 && $voidInLed && in_array(49,$hand,true) && !$qFallen && $card!==49)
                $violForcedQ++;

            $hands[$seat]=array_values(array_filter($hand, fn($id)=>$id!==$card)); // remove played
            if ($card===49) $qFallen=true;
        }
    }
    $pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);
}

echo "\n=== Schoppen Mie rule check ($N games, $sm SM hands) ===\n";
echo "play errors:            $errors\n";
echo "illegal spade LEADS:    $violLead\n";
echo "illegal spade DISCARDS: $violDiscard\n";
echo "♠Q not dumped when void:$violForcedQ\n";
echo (($errors+$violLead+$violDiscard+$violForcedQ)===0 ? "ALL GOOD ✅\n" : "NEEDS ATTENTION ❌\n");