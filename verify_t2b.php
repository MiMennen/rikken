<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';

$N = (int)($argv[1] ?? 400);
$pdo = DB::conn();
$checked=0; $earlyDiscard=0; $lowLeadOfCalled=0; $forcedFollowFail=0; $playErr=0;

for ($i=0;$i<$N;$i++){
    $gid = GameService::create([
        ['type'=>'bot','name'=>'N'],['type'=>'bot','name'=>'O'],
        ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W'],
    ]);
    GameService::start($gid);
    try{ GameRunner::advance($gid); }catch(\Throwable $e){ $playErr++; continue; }

    $hd=$pdo->query("SELECT id,contract,partner_seat,called_ace_suit,dealt_snapshot
                     FROM hands WHERE id=(SELECT current_hand_id FROM games WHERE id=$gid)")->fetch();
    if (in_array($hd['contract'],['rik','rik_beter'],true) && $hd['partner_seat']!==null && $hd['called_ace_suit']!==null){
        $checked++;
        $suit=(int)$hd['called_ace_suit']; $partner=(int)$hd['partner_seat']; $ace=$suit*13+12;
        $hands=json_decode($hd['dealt_snapshot'],true);
        $plays=$pdo->query("SELECT t.trick_number,t.led_suit,p.play_seq,p.seat,p.card
            FROM tricks t JOIN trick_plays p ON p.trick_id=t.id
            WHERE t.hand_id={$hd['id']} ORDER BY t.trick_number,p.play_seq")->fetchAll();

        $acePlayed=false;
        foreach($plays as $pl){
            $seat=(int)$pl['seat']; $card=(int)$pl['card']; $led=(int)$pl['led_suit'];
            $isLead=((int)$pl['play_seq']===0);
            $hand=$hands[$seat];
            $voidInLed=!in_array($led, array_map(fn($id)=>intdiv($id,13),$hand), true);

            if ($seat===$partner && !$acePlayed){
                // strict 1: partner led a LOW called-suit card while holding the Ace
                if ($isLead && intdiv($card,13)===$suit && $card!==$ace) $lowLeadOfCalled++;
                // strict 2: partner discarded the Ace early (different suit led, void, dumped Ace)
                if (!$isLead && $led!==$suit && $voidInLed && $card===$ace) $earlyDiscard++;
                // forced follow: called suit led, partner holds Ace but played something else
                if ($led===$suit && in_array($ace,$hand,true) && $card!==$ace) $forcedFollowFail++;
            }
            $hands[$seat]=array_values(array_filter($hand,fn($id)=>$id!==$card));
            if ($card===$ace) $acePlayed=true;
        }
    }
    $pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);
}

echo "\n=== Strict called-Ace check ($N games, $checked Rik hands) ===\n";
echo "play errors:                 $playErr\n";
echo "partner low-lead of called:  $lowLeadOfCalled\n";
echo "partner early Ace discard:    $earlyDiscard\n";
echo "forced-follow failures:       $forcedFollowFail\n";
echo (($playErr+$lowLeadOfCalled+$earlyDiscard+$forcedFollowFail)===0 ? "ALL STRICT ✅\n":"NEEDS ATTENTION ❌\n");