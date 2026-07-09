<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';
require_once __DIR__ . '/Bot.php';

$N = (int)($argv[1] ?? 400);
$pdo = DB::conn();

function runBatch(int $N, bool $smart, PDO $pdo): array {
    Bot::$drawTrumpSmart = $smart;
    $rikHands=0; $made=0; $wasted=0;
    for ($i=0;$i<$N;$i++){
        $gid = GameService::create([
            ['type'=>'bot','name'=>'N'],['type'=>'bot','name'=>'O'],
            ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W'],
        ]);
        GameService::start($gid);
        GameRunner::advance($gid);
        $hd = $pdo->query("SELECT id,contract,declarer_seat,partner_seat,trump_suit
                           FROM hands WHERE id=(SELECT current_hand_id FROM games WHERE id=$gid)")->fetch();
        if (in_array($hd['contract'],['rik','rik_beter'],true) && $hd['partner_seat']!==null){
            $rikHands++;
            $decl=(int)$hd['declarer_seat']; $part=(int)$hd['partner_seat']; $tr=(int)$hd['trump_suit'];
            $side=[$decl=>1,$part=>1]; $opps=array_values(array_filter([0,1,2,3],fn($s)=>!isset($side[$s])));

            // side tricks
            $tw=$pdo->query("SELECT winner_seat,COUNT(*) c FROM tricks WHERE hand_id={$hd['id']} GROUP BY winner_seat")->fetchAll();
            $won=[0,0,0,0]; foreach($tw as $r)$won[(int)$r['winner_seat']]=(int)$r['c'];
            if ($won[$decl]+$won[$part] >= 8) $made++;

            // wasted trump leads: side led trump while BOTH opponents already void in trump
            $rows=$pdo->query("SELECT t.trick_number,t.led_suit,t.leader_seat,p.seat,p.card
                FROM tricks t JOIN trick_plays p ON p.trick_id=t.id
                WHERE t.hand_id={$hd['id']} ORDER BY t.trick_number,p.play_seq")->fetchAll();
            $voids=[]; $curTrick=-1; $leader=null; $ledSuit=null;
            // group by trick
            $byTrick=[];
            foreach($rows as $r){ $byTrick[(int)$r['trick_number']][]=$r; }
            ksort($byTrick);
            foreach($byTrick as $tn=>$plays){
                $ld=(int)$plays[0]['led_suit']; $lead=(int)$plays[0]['seat'];
                $bothVoid = count(array_intersect($opps,array_keys($voids)))===count($opps);
                if (isset($side[$lead]) && $ld===$tr && $bothVoid) $wasted++;
                // update voids AFTER this trick
                foreach($plays as $pl){
                    if ((int)$pl['led_suit']===$tr && intdiv((int)$pl['card'],13)!==$tr) $voids[(int)$pl['seat']]=1;
                }
            }
        }
        $pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);
    }
    return ['rik'=>$rikHands,'madePct'=>$rikHands?100*$made/$rikHands:0,'wasted'=>$wasted];
}

$off = runBatch($N,false,$pdo);
$on  = runBatch($N,true, $pdo);
Bot::$drawTrumpSmart = true;

echo "\n=== Smart trump management A/B ($N games each) ===\n";
printf("           | Rik hands | side make%% | wasted trump leads\n");
printf(" OLD (off) |   %5d   |   %5.1f%%   |   %d\n", $off['rik'],$off['madePct'],$off['wasted']);
printf(" NEW (on)  |   %5d   |   %5.1f%%   |   %d\n", $on['rik'], $on['madePct'], $on['wasted']);
echo "\nWant: wasted DOWN sharply, make%% same-or-UP.\n";