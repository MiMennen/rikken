<?php
declare(strict_types=1);
require_once __DIR__ . '/CleanupService.php';

$dry = in_array('--dry', $argv, true);
$r = CleanupService::run($dry);

$stamp = date('Y-m-d H:i:s');
$mode  = $dry ? 'DRY-RUN' : 'DELETE';
echo "[$stamp] cleanup ($mode): "
   . "lobby={$r['deleted']['lobby']} "
   . "abandoned={$r['deleted']['abandoned']} "
   . "finished={$r['deleted']['finished']} "
   . "total={$r['total']}\n";