<?php
declare(strict_types=1);

final class DB {
    private static ?PDO $pdo = null;
    private static int $txDepth = 0;      // nesting counter

    public static function conn(): PDO {
        if (self::$pdo === null) {
            $cfg = (require __DIR__ . '/config.php')['db'];
            $dsn = "mysql:host={$cfg['host']};dbname={$cfg['name']};charset={$cfg['charset']}";
            self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }
        return self::$pdo;
    }

    /**
     * Re-entrant transaction. Only the OUTERMOST call begins/commits;
     * nested calls run within the same transaction. On any exception the
     * whole outer transaction rolls back. This lets services that each use
     * tx() safely call one another (e.g. nextHand() -> dealHand()).
     */
    public static function tx(callable $fn): mixed {
        $pdo = self::conn();
        if (self::$txDepth === 0) $pdo->beginTransaction();
        self::$txDepth++;
        try {
            $r = $fn($pdo);
            self::$txDepth--;
            if (self::$txDepth === 0) $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            self::$txDepth--;
            if (self::$txDepth === 0 && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}