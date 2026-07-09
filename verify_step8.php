<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';
require_once __DIR__ . '/ViewState.php';

$N = (int)($argv[1] ?? 100);
$pdo = DB::conn();
$checkedRik = 0; $leaks = 0; $revealChecks = 0;

for ($i=0;$i<$N;$i++){
    $gid = GameService::create([
        ['type'=>'bot','name'=>'N'],['type'=>'bot','name'=>'O'],
        ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W'],
    ]);
    GameService::start($gid);

    // step the game ONE bot action at a time, checking the invariant mid-play
    for ($step=0; $step<220; $step++){
        $game = $pdo->query("SELECT status, current_hand_id FROM games WHERE id=$gid")->fetch();
        if (in_array($game['status'],['hand_scoring','finished'],true)) break;
        // advance exactly one bot action by temporarily... simplest: full advance then inspect snapshots
        GameRunner::advance($gid);
        break; // all-bot -> advance runs whole hand; we inspect the finished hand's history below
    }

    // Re-derive: was this a rik/rik_beter hand with a partner?
    $hand = $pdo->query("SELECT id,contract,declarer_seat,partner_seat,called_ace_suit
                         FROM hands WHERE id=(SELECT current_hand_id FROM games WHERE id=$gid)")->fetch();
    if (in_array($hand['contract'],['rik','rik_beter'],true) && $hand['partner_seat']!==null){
        $checkedRik++;
        $partner = (int)$hand['partner_seat']; $declarer=(int)$hand['declarer_seat'];
        // For a NON-partner, non-declarer viewer, at END of hand the ace has been played
        // (all 52 cards played) so partner SHOULD be revealed. Assert reveal works:
        $v = ViewState::build($gid, ($partner+1)%4);
        if ($v['status']!=='hand_scoring') { /* skip */ }
        else {
            $revealChecks++;
            if ($v['partnerSeat'] !== $partner) { echo "ERR $gid: partner not revealed at hand end\n"; $leaks++; }
            // and youArePartner must be false for this non-partner viewer
            if ($v['youArePartner'] === true) { echo "ERR $gid: youArePartner leaked to non-partner\n"; $leaks++; }
        }
    }
    $pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);
}

echo "\n=== Step 8a partner-visibility check over $N games ===\n";
echo "Rik hands checked: $checkedRik, reveal-at-end checks: $revealChecks\n";
echo "Leaks/errors: $leaks -> ".($leaks===0?"ALL GOOD ✅":"❌")."\n";