<?php
require_once __DIR__ . '/GameService.php';

// make a game, grab the human token
$gid = GameService::create([
    ['type'=>'human','name'=>'Auth'],['type'=>'bot','name'=>'O'],
    ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W'],
]);
GameService::start($gid);
$token = GameService::seatToken($gid, 0);

$base = 'https://mental.cards/rikken';   // adjust if needed
function hit($url,$post=null){
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>10]);
    if($post!==null){curl_setopt($ch,CURLOPT_POST,1);
        curl_setopt($ch,CURLOPT_HTTPHEADER,['Content-Type: application/json']);
        curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($post));}
    $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code,$body];
}

echo "1) no token -> should be 401:\n";
[$c,$b]=hit("$base/state.php"); echo "   HTTP $c  $b\n";

echo "2) garbage token -> should be 403:\n";
[$c,$b]=hit("$base/state.php?token=deadbeef"); echo "   HTTP $c  $b\n";

echo "3) valid token -> should be 200 + JSON state:\n";
[$c,$b]=hit("$base/state.php?token=$token"); echo "   HTTP $c  ".substr($b,0,60)."...\n";

echo "4) old dev fallback (game=$gid&seat=0) -> should NOT work (401):\n";
[$c,$b]=hit("$base/state.php?game=$gid&seat=0"); echo "   HTTP $c  $b\n";

GameService::conn()->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);   // if you exposed conn(); else use DB
echo "done\n";