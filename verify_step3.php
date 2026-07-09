<?php
require_once __DIR__ . '/GameService.php';

$gameId = GameService::create([
    ['type'=>'bot','name'=>'Noord'],
    ['type'=>'bot','name'=>'Oost'],
    ['type'=>'bot','name'=>'Zuid'],
    ['type'=>'bot','name'=>'West'],
]);
echo "Created game $gameId\n";

$res = GameService::start($gameId);
echo "Dealt hand {$res['handId']} (troela=" . ($res['troela'] ? 'YES' : 'no') . ")\n";

$pdo = DB::conn();

// 52 cards dealt, 13 per seat, no duplicates
$counts = $pdo->query(
  "SELECT seat, COUNT(*) c FROM hand_cards WHERE hand_id={$res['handId']} GROUP BY seat ORDER BY seat"
)->fetchAll();
foreach ($counts as $r) echo "  seat {$r['seat']}: {$r['c']} cards\n";

// show each seat's hand human-readably + ace count
require_once __DIR__ . '/rikken_engine.php';
for ($seat = 0; $seat < 4; $seat++) {
    $ids = HandService::seatHand($res['handId'], $seat);
    $cards = ids_to_cards($ids);
    $aces = array_sum(array_map(fn($c)=>$c->isAce()?1:0, $cards));
    echo "  seat $seat ($aces aces): " . implode(' ', array_map('strval',$cards)) . "\n";
}

// game header sanity
$g = $pdo->query("SELECT status,current_seat,hand_number FROM games WHERE id=$gameId")->fetch();
echo "  game status={$g['status']} current_seat={$g['current_seat']} hand#={$g['hand_number']}\n";

// cleanup
$pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gameId]);
echo "Cleaned up game $gameId\n";