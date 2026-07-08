<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

final class CleanupService {

    // Thresholds (minutes). Conservative on purpose.
    public const LOBBY_STALE_MIN    = 60;      // never-started games
    public const ACTIVE_STALE_MIN   = 1440;    // 24h since last action (abandoned mid-game)
    public const FINISHED_KEEP_MIN  = 4320;    // keep finished games 3 days (history/replay)

    /**
     * Delete stale games. Child rows cascade via FK ON DELETE CASCADE.
     * @param bool $dryRun if true, only counts — deletes nothing.
     * @return array breakdown of what was (or would be) removed
     */
    public static function run(bool $dryRun = false): array {
        $pdo = DB::conn();

        $buckets = [
            'lobby'    => ["status='lobby'",                        self::LOBBY_STALE_MIN],
            'abandoned'=> ["status IN ('bidding','declaring','playing','hand_scoring')", self::ACTIVE_STALE_MIN],
            'finished' => ["status='finished'",                     self::FINISHED_KEEP_MIN],
        ];

        $report = ['dryRun'=>$dryRun, 'deleted'=>[], 'total'=>0];

        foreach ($buckets as $label => [$cond, $mins]) {
            $where = "$cond AND last_activity < (NOW() - INTERVAL $mins MINUTE)";

            $count = (int) $pdo->query("SELECT COUNT(*) FROM games WHERE $where")->fetchColumn();
            $report['deleted'][$label] = $count;
            $report['total'] += $count;

            if (!$dryRun && $count > 0) {
                // delete in batches to avoid a giant lock on a busy table
                do {
                    $n = $pdo->exec("DELETE FROM games WHERE $where LIMIT 500");
                } while ($n > 0);
            }
        }
        return $report;
    }
}