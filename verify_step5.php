<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';

$N = (int) ($argv[1] ?? 300);
$pdo = DB::conn();
$trumpDist = [0,0,0,0]; $checked = ['rik'=>0,'rik_beter'=>0,'solo8'=>0,'troela'=>0,'schoppenmie'=>0];
$errors = 0;

for ($i = 0; $i < $N; $i++) {
    $gid = GameService::create([
        ['type'=>'bot','name'=>'N'],['type'=>'bot','name'=>'O'],
        ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W'],
    ]);
    GameService::start($gid);
    GameRunner::advance($gid);   // bid + declare to the brink of play

    $row = $pdo->query("SELECT g.status, h.id hid, h.contract, h.declarer_seat, h.partner_seat,
                               h.trump_suit, h.called_ace_suit, h.leader_seat, g.current_seat
                          FROM games g JOIN hands h ON h.id=g.current_hand_id WHERE g.id=$gid")->fetch();

    $c = $row['contract'];
    if ($c === 'pass_schoppenmie') { $checked['schoppenmie']++; }
    else {
        // must be ready to play
        if ($row['status'] !== 'playing')         { echo "ERR $gid: status {$row['status']} not playing\n"; $errors++; }
        if ($row['current_seat'] != $row['leader_seat']) { echo "ERR $gid: current_seat != leader\n"; $errors++; }

        if (in_array($c, ['rik','rik_beter','troela'], true)) {
            if ($row['partner_seat'] === null)        { echo "ERR $gid $c: no partner\n"; $errors++; }
            elseif ((int)$row['partner_seat'] === (int)$row['declarer_seat']) { echo "ERR $gid $c: partner==declarer\n"; $errors++; }
        }
        if ($row['trump_suit'] === null && $c !== 'misere') { echo "ERR $gid $c: no trump\n"; $errors++; }
        if ($c === 'rik_beter' && (int)$row['trump_suit'] !== 2) { echo "ERR $gid: rik_beter trump not hearts\n"; $errors++; }

        // Troela: trump must NOT be the partner's ace suit
        if ($c === 'troela' && $row['partner_seat'] !== null) {
            $ps = (int)$row['partner_seat'];
            $cards = ids_to_cards(HandService::seatHand((int)$row['hid'], $ps));
            $aceSuits = HandEvaluator::aceSuits($cards);
            // partner may now hold 1 ace (3-ace case) -> trump must differ
            if (!empty($aceSuits) && (int)$row['trump_suit'] === $aceSuits[0]) {
                echo "ERR $gid troela: trump == partner ace suit\n"; $errors++;
            }
        }
        if ($row['trump_suit'] !== null) $trumpDist[(int)$row['trump_suit']]++;
        $checked[$c]++;
    }
    $pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);
}

echo "\n=== Step 5 check over $N games ===\n";
foreach ($checked as $k=>$v) printf("  %-12s %4d\n", $k, $v);
$sym=['♣','♦','♥','♠'];
echo "Trump suit distribution (declared contracts): ";
foreach ($trumpDist as $s=>$n) echo "{$sym[$s]}=$n ";
echo "\nErrors: $errors  -> " . ($errors===0 ? "ALL GOOD ✅" : "SEE ABOVE ❌") . "\n";