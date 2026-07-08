<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/rikken_engine.php';
require_once __DIR__. '/Contracts.php';

final class ScoringService {

    private const TABLE = [
        'rik'       => ['base'=>10,'per'=>5,'type'=>'partner','target'=>8],
        'rik_beter' => ['base'=>10,'per'=>5,'type'=>'partner','target'=>8],
        'troela'    => ['base'=>10,'per'=>5,'type'=>'partner','target'=>8],
        'solo8'     => ['base'=>10,'per'=>5,'type'=>'solo',   'target'=>8],
    ];
    private const KAPOT_BONUS = 35;
    private const SPADE_QUEEN_ID = 3*13 + (12-2); // = 49

    /** Score the just-finished hand, settle seat scores, store summary. */
    public static function scoreHand(int $gameId): array {
        return DB::tx(function (PDO $pdo) use ($gameId) {
            $g = $pdo->prepare('SELECT status, current_hand_id FROM games WHERE id=? FOR UPDATE');
            $g->execute([$gameId]);
            $game = $g->fetch();
            if (!$game || $game['status'] !== 'hand_scoring')
                throw new RuntimeException('Game not awaiting scoring.');
            $handId = (int)$game['current_hand_id'];

            $h = $pdo->prepare('SELECT contract, declarer_seat, partner_seat, result_summary FROM hands WHERE id=?');
            $h->execute([$handId]);
            $hand = $h->fetch();
            if ($hand['result_summary'] !== null)
                return json_decode($hand['result_summary'], true);   // idempotent guard

            // tricks won per seat + which seat took ♠Q and the last trick
            $tw = $pdo->query("SELECT winner_seat, COUNT(*) c FROM tricks WHERE hand_id=$handId GROUP BY winner_seat")->fetchAll();
            $won = [0,0,0,0];
            foreach ($tw as $r) $won[(int)$r['winner_seat']] = (int)$r['c'];

            $contract = $hand['contract'];
            $deltas = [0,0,0,0];
            $summary = ['contract'=>$contract, 'tricksWon'=>$won];

            if ($contract === 'pass_schoppenmie') {
                // ♠Q trick winner
                $qSeat = $pdo->query("SELECT t.winner_seat FROM tricks t
                    JOIN trick_plays p ON p.trick_id=t.id
                    WHERE t.hand_id=$handId AND p.card=".self::SPADE_QUEEN_ID)->fetchColumn();
                $lastSeat = $pdo->query("SELECT winner_seat FROM tricks WHERE hand_id=$handId ORDER BY trick_number DESC LIMIT 1")->fetchColumn();
                foreach ([(int)$qSeat, (int)$lastSeat] as $off) {   // apply each offense
                    for ($s=0;$s<4;$s++) $deltas[$s] += ($s===$off) ? -15 : 5;
                }
                $summary += ['queenSeat'=>(int)$qSeat, 'lastTrickSeat'=>(int)$lastSeat];
            } 
			elseif (Contracts::type($contract) === 'avoid') {
                $declarer = (int)$hand['declarer_seat'];
                $target   = (int) Contracts::target($contract);      // 0 for misère, 1 for piek
                $made     = ($won[$declarer] === $target);
                $M        = Contracts::base($contract);              // flat, no over/undertrick
                $sign     = $made ? 1 : -1;
                for ($s=0;$s<4;$s++) $deltas[$s] = ($s===$declarer) ? $sign*3*$M : -$sign*$M;
                $summary += ['declarer'=>$declarer, 'target'=>$target,
                             'tricks'=>$won[$declarer], 'made'=>$made, 'magnitude'=>$M];
            }
			else {
                $target = (int) Contracts::target($contract);   // solos/rik are ints
                $cfg = ['base'=>Contracts::base($contract), 'per'=>Contracts::per($contract),
                        'type'=>Contracts::type($contract), 'target'=>$target];
                $declarer = (int)$hand['declarer_seat'];
                $partner  = $hand['partner_seat'] !== null ? (int)$hand['partner_seat'] : null;

                $sideTricks = $won[$declarer] + ($partner !== null ? $won[$partner] : 0);
                $made = $sideTricks >= $cfg['target'];
                $diff = abs($sideTricks - $cfg['target']);
                $M = $cfg['base'] + $cfg['per'] * $diff;
                if ($cfg['type']==='partner' && $made && $sideTricks === 13) $M += self::KAPOT_BONUS;
                $sign = $made ? 1 : -1;

                if ($cfg['type'] === 'partner') {
                    $winners = [$declarer, $partner];
                    for ($s=0;$s<4;$s++)
                        $deltas[$s] = in_array($s,$winners,true) ? $sign*$M : -$sign*$M;
                } else { // solo: declarer vs 3
                    for ($s=0;$s<4;$s++)
                        $deltas[$s] = ($s===$declarer) ? $sign*3*$M : -$sign*$M;
                }
                $summary += ['declarer'=>$declarer,'partner'=>$partner,
                             'sideTricks'=>$sideTricks,'target'=>$cfg['target'],
                             'made'=>$made,'magnitude'=>$M];
            }

            $summary['deltas'] = $deltas;
            if (array_sum($deltas) !== 0) throw new RuntimeException('Scoring not zero-sum!'); // invariant

            // apply to cumulative scores
            $upd = $pdo->prepare('UPDATE game_seats SET score = score + ? WHERE game_id=? AND seat=?');
            for ($s=0;$s<4;$s++) $upd->execute([$deltas[$s], $gameId, $s]);

            $pdo->prepare('UPDATE hands SET result_summary=?, status="scored" WHERE id=?')
                ->execute([json_encode($summary, JSON_THROW_ON_ERROR), $handId]);

            return $summary;
        });
    }
}