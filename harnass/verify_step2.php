<?php
require __DIR__ . '/DeckRepository.php';

// 1. codec round-trips for all 52 ids
for ($i = 0; $i < 52; $i++) {
    $c = Card::fromId($i);
    if ($c->id() !== $i) { exit("CODEC FAIL at $i\n"); }
}
echo "Codec: all 52 ids round-trip OK\n";

// 2. make a throwaway lobby game, init + load the deck
$pdo = DB::conn();
$pdo->exec("INSERT INTO games (status) VALUES ('lobby')");
$gameId = (int) $pdo->lastInsertId();

DeckRepository::init($gameId);
$deck = DeckRepository::load($gameId);

// it must be a permutation of 0..51 (all unique, all present)
$ids = cards_to_ids($deck);
sort($ids);
$ok = $ids === range(0, 51);
echo "Deck init+load: " . ($ok ? "valid 52-card permutation OK" : "BROKEN") . "\n";
echo "Top 5 cards of stack: ";
foreach (array_slice($deck, 0, 5) as $c) echo $c . ' ';
echo "\n";

// 3. cleanup
$pdo->prepare("DELETE FROM games WHERE id = ?")->execute([$gameId]);
echo "Done (test game $gameId removed).\n";