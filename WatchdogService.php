<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/GameRunner.php';

final class WatchdogService {

    // Don't touch games younger than this (avoids racing an in-flight request).
    public const NUDGE_STALE_SEC   = 30;
    // Bound the work per tick so one run stays fast.
    public const MAX_GAMES_PER_RUN = 25;

    /**
     * Find games parked on a BOT's turn that haven't moved recently, and
     * drive them forward. Human-turn games are intentionally left alone.
     * @return array{scanned:int, nudged:int, ids:int[]}
     */
    public static function run(): array {
        $pdo = DB::conn();

        $sql = "SELECT g.id
                  FROM games g
                  JOIN game_seats s ON s.game_id = g.id AND s.seat = g.current_seat
                 WHERE g.status IN ('bidding','declaring','playing')
                   AND s.seat_type = 'bot'
                   AND g.last_activity < (NOW() - INTERVAL ".self::NUDGE_STALE_SEC." SECOND)
                 ORDER BY g.last_activity ASC
                 LIMIT ".self::MAX_GAMES_PER_RUN;

        $ids = array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));

        $nudged = [];
        foreach ($ids as $gid) {
            try { GameRunner::advance($gid); $nudged[] = $gid; }
            catch (\Throwable $e) { error_log("watchdog: game $gid failed: ".$e->getMessage()); }
        }
        return ['scanned'=>count($ids), 'nudged'=>count($nudged), 'ids'=>$nudged];
    }
}