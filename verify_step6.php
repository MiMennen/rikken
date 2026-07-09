<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';

$N = (int) ($argv[1] ?? 200);
$pdo = DB::conn();
$errors = 0; $reachedScoring = 0;

for ($i = 0; $i < $N; $i++) {
    $gid = GameService::create([
        ['type'=>'bot','name'=>'N'],['type'=>'bot','name'=>'O'],
        ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W'],
    ]);
    GameService::start($gid);
    GameRunner::advance($gid);   // should now run deal->bid->declare->PLAY to the end

    $game = $pdo->query("SELECT status, current_hand_id FROM games WHERE id=$gid")->fetch();
    $hid  = (int)$game['current_hand_id'];

    if ($game['status'] !== 'hand_scoring') { echo "ERR $gid: ended in {$game['status']}\n"; $errors++; }
    else $reachedScoring++;

    // exactly 13 tricks, each with a winner and 4 cards
    $tr = $pdo->query("SELECT COUNT(*) FROM tricks WHERE hand_id=$hid AND winner_seat IS NOT NULL")->fetchColumn();
    if ((int)$tr !== 13) { echo "ERR $gid: $tr completed tricks\n"; $errors++; }
    $bad = $pdo->query("SELECT COUNT(*) FROM tricks t WHERE t.hand_id=$hid
                        AND (SELECT COUNT(*) FROM trick_plays p WHERE p.trick_id=t.id)<>4")->fetchColumn();
    if ((int)$bad !== 0) { echo "ERR $gid: $bad tricks without 4 cards\n"; $errors++; }
    // all 52 cards played
    $played = $pdo->query("SELECT COUNT(*) FROM hand_cards WHERE hand_id=$hid AND played=1")->fetchColumn();
    if ((int)$played !== 52) { echo "ERR $gid: $played/52 cards played\n"; $errors++; }

    // show trick-count won per seat for the first game (sanity / drama check)
    if ($i === 0) {
        $w = $pdo->query("SELECT winner_seat, COUNT(*) c FROM tricks WHERE hand_id=$hid GROUP BY winner_seat ORDER BY winner_seat")->fetchAll();
        $con = $pdo->query("SELECT contract, declarer_seat, partner_seat, trump_suit FROM hands WHERE id=$hid")->fetch();
        echo "sample game $gid: {$con['contract']} declarer s{$con['declarer_seat']} partner ".
             ($con['partner_seat']??'-')." trump {$con['trump_suit']} | tricks: ";
        foreach ($w as $r) echo "s{$r['winner_seat']}={$r['c']} ";
        echo "\n";
    }
    $pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);
}

echo "\n=== Step 6 over $N games ===\n";
echo "Reached hand_scoring: $reachedScoring/$N\n";
echo "Errors: $errors -> " . ($errors===0 ? "ALL GOOD ✅" : "SEE ABOVE ❌") . "\n";