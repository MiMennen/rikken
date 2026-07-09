<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/CleanupService.php';

$pdo = DB::conn();

// 1. make a stale lobby game (back-date its activity by 2 hours)
$stale = GameService::create([
    ['type'=>'human','name'=>'Ghost'],['type'=>'bot','name'=>'O'],
    ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W'],
]);
$pdo->prepare("UPDATE games SET last_activity = NOW() - INTERVAL 2 HOUR WHERE id=?")->execute([$stale]);

// 2. make a FRESH lobby game (should survive)
$fresh = GameService::create([
    ['type'=>'human','name'=>'Live'],['type'=>'bot','name'=>'O'],
    ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W'],
]);

echo "stale=$stale (2h old lobby), fresh=$fresh (new lobby)\n";
$before = $pdo->query("SELECT COUNT(*) FROM games")->fetchColumn();

$r = CleanupService::run(false);
print_r($r['deleted']);

$staleGone = !$pdo->query("SELECT 1 FROM games WHERE id=$stale")->fetchColumn();
$freshKept = (bool)$pdo->query("SELECT 1 FROM games WHERE id=$fresh")->fetchColumn();
echo "stale removed: ".($staleGone?"YES ✅":"NO ❌")."\n";
echo "fresh kept:    ".($freshKept?"YES ✅":"NO ❌")."\n";

// confirm cascade: no orphan child rows for the deleted game
$orphans = $pdo->query("SELECT COUNT(*) FROM hands WHERE game_id=$stale")->fetchColumn();
echo "orphan child rows for stale game: $orphans ".($orphans==0?"✅":"❌")."\n";

// cleanup our fresh test game
$pdo->prepare("DELETE FROM games WHERE id=?")->execute([$fresh]);
echo "cleaned up fresh test game $fresh\n";