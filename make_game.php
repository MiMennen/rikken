<?php
require_once __DIR__ . '/GameService.php';   // adjust path to your logic dir
$gid = GameService::create([
    ['type'=>'human','name'=>'You'],
    ['type'=>'bot','name'=>'Oost'],
    ['type'=>'bot','name'=>'Zuid'],
    ['type'=>'bot','name'=>'West'],
]);
GameService::start($gid);
require_once __DIR__ . '/GameRunner.php';
GameRunner::advance($gid);   // let bots act up to your turn
echo "Open: index.html?game=$gid&seat=0\n";