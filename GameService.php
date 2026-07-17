<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/DeckRepository.php';
require_once __DIR__ . '/HandService.php';

final class GameService {

    /**
     * Create a game with 4 seats. $seats is an array of 4:
     *   ['type'=>'human','user_id'=>1,'name'=>'Anne'] or ['type'=>'bot','name'=>'Bot Zuid']
     */
public static function create(array $seats, int $maxHands = 8, ?int $targetScore = null, ?string $rulesJson = null): int {
        if (count($seats) !== 4) throw new InvalidArgumentException('Need exactly 4 seats.');
        return DB::tx(function (PDO $pdo) use ($seats, $maxHands, $targetScore, $rulesJson) {
            $ins = $pdo->prepare("INSERT INTO games (status, max_hands, target_score, rules_config) VALUES ('lobby', ?, ?, ?)");
            $ins->execute([$maxHands, $targetScore, $rulesJson]);
            $gameId = (int) $pdo->lastInsertId();
            $q = $pdo->prepare(
                'INSERT INTO game_seats (game_id, seat, seat_type, user_id, display_name, seat_token)
                 VALUES (?,?,?,?,?,?)');
            foreach ($seats as $i => $s) {
                $q->execute([
                    $gameId, $i, $s['type'],
                    $s['type'] === 'human' ? ($s['user_id'] ?? null) : null,
                    $s['name'], bin2hex(random_bytes(16)),
                ]);
            }
            return $gameId;
        });
    }

    /** The token for a given seat (used to build the human's play link). */
    public static function seatToken(int $gameId, int $seat): ?string {
        $s = DB::conn()->prepare('SELECT seat_token FROM game_seats WHERE game_id=? AND seat=?');
        $s->execute([$gameId, $seat]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    /** token -> ['game'=>int,'seat'=>int] or null. The auth primitive. */
    public static function resolveToken(string $token): ?array {
        $s = DB::conn()->prepare('SELECT game_id, seat FROM game_seats WHERE seat_token=?');
        $s->execute([$token]);
        $r = $s->fetch();
        return $r ? ['game'=>(int)$r['game_id'], 'seat'=>(int)$r['seat']] : null;
    }

    /** Shuffle the deck (session start) and deal the first hand. */
    public static function start(int $gameId): array {
        DeckRepository::init($gameId);              // full shuffle at session start
        return HandService::dealHand($gameId);
    }
/**
     * Start the next hand: advance the persistent deck (gather+cut, or reshuffle
     * on Troela), rotate the dealer, deal. Honours target_score end condition.
     * @return array{status:string, handId?:int, troela?:bool}
     */
    public static function nextHand(int $gameId): array {
        require_once __DIR__ . '/DeckRepository.php';
        require_once __DIR__ . '/HandService.php';

        return DB::tx(function (PDO $pdo) use ($gameId) {
            $g = $pdo->prepare('SELECT status, current_hand_id, dealer_seat, target_score, max_hands FROM games WHERE id=? FOR UPDATE');
            $g->execute([$gameId]);
            $game = $g->fetch();
            $prevHandId = (int)$game['current_hand_id'];

            // --- end conditions: max hands reached, or score target hit ---
            $handsPlayed = (int)$pdo->query("SELECT COUNT(*) FROM hands WHERE game_id=$gameId AND status='scored'")->fetchColumn();
            $maxHands    = $game['max_hands'] !== null ? (int)$game['max_hands'] : null;
            $hitHands    = $maxHands !== null && $handsPlayed >= $maxHands;

            $hitScore = false;
            if ($game['target_score'] !== null) {
                $max = (int)$pdo->query("SELECT MAX(score) FROM game_seats WHERE game_id=$gameId")->fetchColumn();
                $hitScore = $max >= (int)$game['target_score'];
            }

            if ($hitHands || $hitScore) {
                $pdo->prepare("UPDATE games SET status='finished', current_seat=NULL WHERE id=?")->execute([$gameId]);
                return ['status'=>'finished'];
            }

            // reconstruct per-player piles from played tricks...
            $rows = $pdo->query("SELECT t.winner_seat, p.card
                FROM tricks t JOIN trick_plays p ON p.trick_id=t.id
                WHERE t.hand_id=$prevHandId ORDER BY t.trick_number, p.play_seq")->fetchAll();
            $piles = [[],[],[],[]];
            $seen  = [];
            foreach ($rows as $r) { $piles[(int)$r['winner_seat']][] = Card::fromId((int)$r['card']); $seen[(int)$r['card']]=true; }

            // ...plus any UNPLAYED cards (early-terminated hand): append to their OWNER's pile.
            $left = $pdo->query("SELECT seat, card FROM hand_cards
                WHERE hand_id=$prevHandId AND played=0 ORDER BY seat, card")->fetchAll();
            foreach ($left as $r) {
                if (!isset($seen[(int)$r['card']]))
                    $piles[(int)$r['seat']][] = Card::fromId((int)$r['card']);
            }

            $troela = (bool)$pdo->query("SELECT troela_occurred FROM hands WHERE id=$prevHandId")->fetchColumn();

            // advance the persistent deck (FINALLY wiring the validated engine into live play)
            DeckRepository::advance($gameId, $piles, $troela);

            // rotate dealer clockwise, then deal
            $newDealer = ((int)$game['dealer_seat'] + 1) % 4;
            $pdo->prepare('UPDATE games SET dealer_seat=? WHERE id=?')->execute([$newDealer, $gameId]);

            $res = HandService::dealHand($gameId);   // sets status bidding/declaring + current_seat
            return ['status'=>'dealt'] + $res;
        });
    }
	
	/** Bump a game's activity heartbeat. Call on every human/bot action. */
    public static function touch(int $gameId): void {
        DB::conn()->prepare('UPDATE games SET last_activity = NOW() WHERE id = ?')->execute([$gameId]);
    }
}
