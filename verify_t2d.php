<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/PlayService.php';
require_once __DIR__ . '/GameRunner.php';
require_once __DIR__ . '/ViewState.php';
require_once __DIR__ . '/rikken_engine.php';

$pdo = DB::conn();
$C = fn(int $suit,int $rank)=>$suit*13+($rank-2);   // card id helper

// --- 1. create a game (4 seats), but DO NOT start/deal it ---
$gid = GameService::create([
    ['type'=>'human','name'=>'Decl'],['type'=>'bot','name'=>'O'],
    ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W'],
], 8);

// --- 2. insert OUR OWN hand row (hand_number 1) ---
$pdo->prepare("INSERT INTO hands (game_id,hand_number,dealer_seat,leader_seat,
        contract,declarer_seat,partner_seat,trump_suit,called_ace_suit,status,dealt_snapshot)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$gid,1,3,0,'rik',0,2,3,0,'playing','{}']);   // dealer=3 so leader=0; clubs called; ♠ trump
$hid = (int)$pdo->lastInsertId();

// point the game at our hand, in playing state, seat 0 to act
$pdo->prepare("UPDATE games SET current_hand_id=?, hand_number=1, dealer_seat=3,
        status='playing', current_seat=0 WHERE id=?")->execute([$hid,$gid]);

// --- 3. controlled layout: seat0 VOID in clubs; seat2 holds all clubs incl A♣ ---
$seat0 = [$C(3,14),$C(3,13),$C(3,12),$C(3,11),$C(3,10),   // ♠ A K Q J T
          $C(2,14),$C(2,13),$C(2,12),$C(2,11),            // ♥ A K Q J
          $C(1,14),$C(1,13),$C(1,12),$C(1,11)];           // ♦ A K Q J  -> 13, zero clubs
$seat2 = [$C(0,14),$C(0,13),$C(0,12),$C(0,11),$C(0,10),   // ♣ A K Q J T (A♣ = called Ace)
          $C(0,9),$C(0,8),$C(0,7),$C(0,6),$C(0,5),
          $C(0,4),$C(0,3),$C(0,2)];                       // all 13 clubs
$used = array_merge($seat0,$seat2);
$rest = array_values(array_diff(range(0,51),$used));      // remaining 26
$seat1 = array_slice($rest,0,13);
$seat3 = array_slice($rest,13,13);

$ins=$pdo->prepare("INSERT INTO hand_cards (hand_id,seat,card) VALUES (?,?,?)");
foreach([0=>$seat0,1=>$seat1,2=>$seat2,3=>$seat3] as $st=>$cards)
    foreach($cards as $c) $ins->execute([$hid,$st,$c]);

// sanity: 52 unique cards dealt
$cnt=(int)$pdo->query("SELECT COUNT(*) FROM hand_cards WHERE hand_id=$hid")->fetchColumn();
$dis=(int)$pdo->query("SELECT COUNT(DISTINCT card) FROM hand_cards WHERE hand_id=$hid")->fetchColumn();
echo "cards dealt: $cnt (distinct $dis) ".($cnt===52&&$dis===52?"✅":"❌")."\n";
echo "Setup: seat0 declarer void in ♣, A♣ with seat2 (partner), ♠ trump, seat0 leads.\n";

// --- 4. is the blind lead offered? ---
$vs = ViewState::build($gid,0);
echo "canBlindLead: ".(!empty($vs['canBlindLead'])?"YES ✅":"NO ❌")."\n";

// --- 5. blind lead: hide ♦J, announce clubs(0) ---
try{
    PlayService::playCard($gid,0,$C(1,11),true,0);
    echo "blind lead played (face-down ♦J, announced ♣)\n";
    GameRunner::advance($gid);
}catch(\Throwable $e){ echo "BLIND LEAD ERROR: ".$e->getMessage()."\n"; }

// --- 6. inspect trick 1 ---
$t1=$pdo->query("SELECT id,led_suit,winner_seat FROM tricks WHERE hand_id=$hid AND trick_number=1")->fetch();
if(!$t1){ echo "no trick recorded ❌\n"; $pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]); exit; }
$plays=$pdo->query("SELECT seat,card,face_down FROM trick_plays WHERE trick_id={$t1['id']} ORDER BY play_seq")->fetchAll();
echo "trick1 led_suit={$t1['led_suit']} winner=seat{$t1['winner_seat']}\n";
foreach($plays as $p){
    echo "  seat{$p['seat']}: ".Card::fromId((int)$p['card']).($p['face_down']?' (face-down)':'')."\n";
}
$aceIn=(bool)$pdo->query("SELECT 1 FROM trick_plays WHERE trick_id={$t1['id']} AND card=".$C(0,14))->fetchColumn();

echo "\n=== Result ===\n";
echo "announced led suit = clubs: ".($t1['led_suit']==0?"YES ✅":"NO ❌")."\n";
echo "called A♣ forced out:       ".($aceIn?"YES ✅":"NO ❌")."\n";
echo "trick resolved:             ".($t1['winner_seat']!==null?"YES ✅":"NO ❌")."\n";
echo "face-down ♦J did not win:   ".($t1['winner_seat']!=0?"YES ✅":"NO (declarer won ❌)")."\n";
echo (($t1['led_suit']==0 && $aceIn && $t1['winner_seat']!==null && $t1['winner_seat']!=0)?"ALL GOOD ✅\n":"NEEDS ATTENTION ❌\n");

$pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);