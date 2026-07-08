<?php
declare(strict_types=1);
require_once __DIR__ . '/WatchdogService.php';
require_once __DIR__ . '/CleanupService.php';

// --- prevent overlapping runs (if a tick ever exceeds 1 minute) ---
$lock = fopen(__DIR__ . '/watchdog.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[".date('H:i:s')."] watchdog: previous run still active, skipping\n");
    exit(0);
}

$stamp = date('Y-m-d H:i:s');
try {
    $w = WatchdogService::run();
    // Cleanup runs less often to save work: only on the top of the hour.
    $c = ((int)date('i') === 0) ? CleanupService::run(false) : null;

    $line = "[$stamp] watchdog: scanned={$w['scanned']} nudged={$w['nudged']}";
    if ($w['nudged'] > 0) $line .= " ids=".implode(',', $w['ids']);
    if ($c !== null)      $line .= " | cleanup total={$c['total']} "
        ."(lobby={$c['deleted']['lobby']} abandoned={$c['deleted']['abandoned']} finished={$c['deleted']['finished']})";
    echo $line . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "[$stamp] watchdog ERROR: ".$e->getMessage()."\n");
} finally {
    flock($lock, LOCK_UN); fclose($lock);
}