<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rikken_engine.php';

final class DeckRepository {

    /** Session start: fresh shuffled 52-stack written to the game row. */
    public static function init(int $gameId): void {
        $deck = Deck::fresh();
        $deck->shuffle();
        self::save($gameId, $deck->cards);
    }

    /** Load the persistent stack as Card[] (deal order). */
    public static function load(int $gameId): array {
        $stmt = DB::conn()->prepare('SELECT deck_state FROM games WHERE id = ?');
        $stmt->execute([$gameId]);
        $json = $stmt->fetchColumn();
        if ($json === false || $json === null) {
            throw new RuntimeException("Game $gameId has no deck_state (call init first).");
        }
        $ids = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($ids) || count($ids) !== 52) {
            throw new RuntimeException("Corrupt deck_state for game $gameId.");
        }
        return ids_to_cards($ids);
    }

    /** Persist a 52-card stack (Card[]) back to the game row. */
    public static function save(int $gameId, array $cards): void {
        if (count($cards) !== 52) {
            throw new InvalidArgumentException('Deck must be exactly 52 cards.');
        }
        $json = json_encode(cards_to_ids($cards), JSON_THROW_ON_ERROR);
        $stmt = DB::conn()->prepare('UPDATE games SET deck_state = ? WHERE id = ?');
        $stmt->execute([$json, $gameId]);
    }

    /**
     * Advance the deck between hands using our VALIDATED engine:
     *   normal hand -> gather(per-player piles) + cut
     *   troela      -> full reshuffle (decision 1b)
     * @param Card[][] $winnerPiles seats 0..3 (from the hand just played)
     */
    public static function advance(int $gameId, array $winnerPiles, bool $troelaOccurred): void {
        if ($troelaOccurred) {
            self::init($gameId);                       // full reshuffle
            return;
        }
        $stmt = DB::conn()->prepare('SELECT imperfection FROM games WHERE id = ?');
        $stmt->execute([$gameId]);
        $imp = (float) $stmt->fetchColumn();           // locked 0.15
        $stack = Gather::cut(Gather::gather($winnerPiles, $imp));
        self::save($gameId, $stack);
    }
}