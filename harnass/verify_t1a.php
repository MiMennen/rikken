<?php
require_once __DIR__ . '/GameService.php';
$gid = GameService::create([
    ['type'=>'human','name'=>'Test'],['type'=>'bot','name'=>'O'],
    ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W'],
]);
$tok = GameService::seatToken($gid, 0);
echo "token: $tok (len ".strlen($tok).")\n";
$res = GameService::resolveToken($tok);
echo ($res && $res['game']===$gid && $res['seat']===0) ? "resolve OK ✅\n" : "resolve BROKEN ❌\n";
// bots must have NO usable token collisions and each seat a distinct token
$n = DB::conn()->query("SELECT COUNT(DISTINCT seat_token) FROM game_seats WHERE game_id=$gid")->fetchColumn();
echo ($n==4) ? "4 distinct tokens ✅\n" : "token dup ❌\n";
DB::conn()->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);
echo "cleaned up $gid\n";