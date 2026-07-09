<?php
require_once __DIR__ . '/GameService.php';
require_once __DIR__ . '/GameRunner.php';

$pdo = DB::conn();
$LEN = 4;
$gid = GameService::create([
    ['type'=>'bot','name'=>'N'],['type'=>'bot','name'=>'O'],
    ['type'=>'bot','name'=>'Z'],['type'=>'bot','name'=>'W'],
], $LEN);
GameService::start($gid);

$hands=0;
for ($i=0;$i<20;$i++){
    GameRunner::advance($gid);                     // -> hand_scoring
    $st=$pdo->query("SELECT status FROM games WHERE id=$gid")->fetchColumn();
    if ($st==='finished') break;
    $r=GameService::nextHand($gid);
    $hands++;
    if (($r['status']??'')==='finished'){ echo "finished after $hands hands\n"; break; }
}

$g=$pdo->query("SELECT status FROM games WHERE id=$gid")->fetch();
echo "final status: {$g['status']} (expected finished)\n";
$scored=$pdo->query("SELECT COUNT(*) FROM hands WHERE game_id=$gid AND status='scored'")->fetchColumn();
echo "hands scored: $scored (expected $LEN)\n";

$rows=$pdo->query("SELECT display_name,score FROM game_seats WHERE game_id=$gid ORDER BY score DESC")->fetchAll();
$sum=0; foreach($rows as $r){ echo "  {$r['display_name']}: ".sprintf('%+d',$r['score'])."\n"; $sum+=$r['score']; }
echo "score sum: $sum (expected 0, zero-sum)\n";
echo (($g['status']==='finished' && (int)$scored===$LEN && $sum===0)?"ALL GOOD ✅\n":"❌\n");

$pdo->prepare("DELETE FROM games WHERE id=?")->execute([$gid]);