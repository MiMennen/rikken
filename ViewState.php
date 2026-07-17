<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/HandService.php';
require_once __DIR__ . '/PlayService.php';
require_once __DIR__ . '/rikken_engine.php';
require_once __DIR__ . '/Rules.php'

final class ViewState {

    /** The card whose play publicly reveals the partner (or null). */
    private static function revealCardId(array $hand): ?int {
        $c   = $hand['contract'];
        $cas = $hand['called_ace_suit'];
        if ($c === 'rik' || $c === 'rik_beter')
            return $cas === null ? null : ((int)$cas * 13 + 12);          // called Ace
        if ($c === 'troela') {
            if ($cas !== null) return (int)$cas * 13 + 11;                // 4-ace: called King
            // 3-ace: the 4th Ace = suit the declarer lacks
            $snap = json_decode($hand['dealt_snapshot'], true);
            $declarer = (int)$hand['declarer_seat'];
            $declAceSuits = [];
            foreach ($snap[$declarer] as $cid) if ($cid % 13 === 12) $declAceSuits[] = intdiv($cid,13);
            foreach ([0,1,2,3] as $sv) if (!in_array($sv,$declAceSuits,true)) return $sv*13+12;
        }
        return null;
    }

    private static function cardPlayed(PDO $pdo, int $handId, int $cardId): bool {
        $s = $pdo->prepare('SELECT 1 FROM trick_plays p JOIN tricks t ON p.trick_id=t.id
                            WHERE t.hand_id=? AND p.card=? LIMIT 1');
        $s->execute([$handId, $cardId]);
        return (bool)$s->fetchColumn();
    }

    /** Build the state visible to $viewerSeat (0..3). */
    public static function build(int $gameId, int $viewerSeat): array {
        $pdo = DB::conn();
        $g = $pdo->prepare('SELECT status, hand_number, dealer_seat, current_seat, current_hand_id, max_hands, target_score, rules_config FROM games WHERE id=?');
        $g->execute([$gameId]);
        $game = $g->fetch();
        if (!$game) throw new RuntimeException("No game $gameId");

        $seats = $pdo->prepare('SELECT seat, seat_type, display_name, score FROM game_seats WHERE game_id=? ORDER BY seat');
        $seats->execute([$gameId]);
        $seatRows = $seats->fetchAll();

        $out = [
            'gameId'      => $gameId,
            'status'      => $game['status'],
            'handNumber'  => (int)$game['hand_number'],
            'dealerSeat'  => (int)$game['dealer_seat'],
            'currentSeat' => $game['current_seat'] !== null ? (int)$game['current_seat'] : null,
            'viewerSeat'  => $viewerSeat,
            'yourTurn'    => $game['current_seat'] !== null && (int)$game['current_seat'] === $viewerSeat,
            'seats'       => array_map(fn($r)=>[
                                'seat'=>(int)$r['seat'], 'type'=>$r['seat_type'],
                                'name'=>$r['display_name'], 'score'=>(int)$r['score']
                             ], $seatRows),
        ];
		$out['houseRules'] = Rules::fromJson($game['rules_config'] ?? null)->summary();

        if ($game['current_hand_id'] === null) return $out;   // lobby
        $handId = (int)$game['current_hand_id'];

        $h = $pdo->prepare('SELECT contract, declarer_seat, partner_seat, trump_suit,
                                   called_ace_suit, leader_seat, status, result_summary, dealt_snapshot
                            FROM hands WHERE id=?');
        $h->execute([$handId]);
        $hand = $h->fetch();

        $out['contract']    = $hand['contract'];
        $out['trumpSuit']   = $hand['trump_suit'] !== null ? (int)$hand['trump_suit'] : null;
        $out['declarerSeat']= $hand['declarer_seat'] !== null ? (int)$hand['declarer_seat'] : null;
        $out['calledAceSuit'] = $hand['called_ace_suit'] !== null ? (int)$hand['called_ace_suit'] : null;
		
		// ---- OPEN avoidance: bidder's hand is face-up after trick 1 ----
        $out['openHand'] = null;
        if (in_array($hand['contract'], ['open_misere','open_piek','open_misere_praatje'], true)
            && $hand['declarer_seat'] !== null) {
            $tricksDone = (int)$pdo->query("SELECT COUNT(*) FROM tricks WHERE hand_id=$handId AND winner_seat IS NOT NULL")->fetchColumn();
            if ($tricksDone >= 1) {
                $decl = (int)$hand['declarer_seat'];
                $ids = HandService::seatHand($handId, $decl);
                $out['openHand'] = ['seat'=>$decl,
                    'cards'=>array_map(fn($id)=>['id'=>$id,'label'=>(string)Card::fromId($id)], $ids),
                    'praatje'=>($hand['contract']==='open_misere_praatje')];
            }
        }

        // ---- partner visibility (the critical rule) ----
        $partner = $hand['partner_seat'] !== null ? (int)$hand['partner_seat'] : null;
        $revealCard = self::revealCardId($hand);
        $revealed = $partner !== null && $revealCard !== null && self::cardPlayed($pdo,$handId,$revealCard);
        $out['partnerRevealed'] = $revealed;
        $out['partnerSeat']     = $revealed ? $partner : null;      // public only when revealed
        $out['youArePartner']   = ($partner !== null && $viewerSeat === $partner); // private to partner

        // ---- own hand ----
        $ownIds = HandService::seatHand($handId, $viewerSeat);      // unplayed
        $out['hand'] = array_map(fn($id)=>['id'=>$id,
            'suit'=>intdiv($id,13),'rank'=>($id%13)+2,'label'=>(string)Card::fromId($id)], $ownIds);

        // ---- current trick (public) ----
        $cur = PlayService::currentTrick($pdo, $handId);
        $out['currentTrick'] = $cur['trick'] === null ? [] : array_map(function($p) use ($viewerSeat) {
            $faceDown = (int)($p['face_down'] ?? 0) === 1;
            $show = !$faceDown || (int)$p['seat'] === $viewerSeat;  // only owner sees their own hidden card
            return [
                'seat'=>(int)$p['seat'],
                'card'=>$show ? (int)$p['card'] : null,
                'faceDown'=>$faceDown,
                'label'=>$show ? (string)Card::fromId((int)$p['card']) : '🂠',
            ];
        }, $cur['plays']);

        // ---- legal moves (only when it's the viewer's turn) ----
        $out['legalCards'] = ($out['yourTurn'] && $game['status']==='playing')
            ? PlayService::legalCards($handId, $viewerSeat) : [];

		// ---- blind lead availability (declarer, on lead, void in called suit, Ace still out) ----
        $out['canBlindLead'] = false;
        if ($out['yourTurn'] && $game['status']==='playing'
            && $hand['declarer_seat'] !== null && (int)$hand['declarer_seat'] === $viewerSeat
            && $hand['called_ace_suit'] !== null) {
            $cur2 = PlayService::currentTrick($pdo, $handId);
            $onLead = ($cur2['trick']===null || count($cur2['plays'])===0);
            $void = true;
            foreach (HandService::seatHand($handId,$viewerSeat) as $id)
                if (intdiv($id,13) === (int)$hand['called_ace_suit']) { $void=false; break; }
            // Ace not yet played?
            $aceId = (int)$hand['called_ace_suit']*13+12;
            $s=$pdo->prepare('SELECT 1 FROM trick_plays p JOIN tricks t ON p.trick_id=t.id WHERE t.hand_id=? AND p.card=? LIMIT 1');
            $s->execute([$handId,$aceId]); $aceAlreadyOut=(bool)$s->fetchColumn();
            $out['canBlindLead'] = $onLead && $void && !$aceAlreadyOut;
            $out['calledSuitForBlind'] = (int)$hand['called_ace_suit'];
        }

        // ---- tricks won (public) ----
        $tw = $pdo->query("SELECT winner_seat, COUNT(*) c FROM tricks WHERE hand_id=$handId AND winner_seat IS NOT NULL GROUP BY winner_seat")->fetchAll();
        $won=[0,0,0,0]; foreach($tw as $r) $won[(int)$r['winner_seat']]=(int)$r['c'];
        $out['tricksWon'] = $won;

        // ---- bidding options (only when it's the viewer's turn to bid) ----
        if ($out['yourTurn'] && $game['status']==='bidding') {
            require_once __DIR__.'/BiddingService.php';
            require_once __DIR__.'/Contracts.php';
            $st = BiddingService::state($handId);
            $out['bidHighestRank'] = $st['highestRank'];
            $out['bidOptions'] = Contracts::biddableAbove($st['highestRank']); // e.g. ['solo9','solo10',...]
        }
		
		// ---- declaration announcement (start of play, before any card) ----
        $out['playStarted'] = false;
        if ($game['status']==='playing') {
            $anyPlay = (int)$pdo->query("SELECT COUNT(*) FROM trick_plays p
                JOIN tricks t ON p.trick_id=t.id WHERE t.hand_id=$handId")->fetchColumn();
            $out['playStarted'] = $anyPlay > 0;   // false = we're at the very start of the hand
        }
		
		// ---- Troela: the partner may not choose the 4th-ace suit as trump ----
        $out['forbiddenTrump'] = null;
        if ($out['yourTurn'] && $game['status']==='declaring'
            && $hand['contract']==='troela' && $hand['partner_seat']!==null
            && (int)$hand['partner_seat']===$viewerSeat) {
            $cards = ids_to_cards(HandService::seatHand($handId, $viewerSeat));
            $aceSuits = HandEvaluator::aceSuits($cards);          // partner holds the 4th ace
            $out['forbiddenTrump'] = $aceSuits[0] ?? null;        // its suit is forbidden as trump
        }

        // ---- result (once scored) ----
        if ($hand['result_summary'] !== null)
            $out['lastResult'] = json_decode($hand['result_summary'], true);

		// ---- full play log (drives client-side trick animation) ----
        $log = $pdo->prepare(
            'SELECT t.trick_number, t.winner_seat, p.play_seq, p.seat, p.card
               FROM tricks t JOIN trick_plays p ON p.trick_id = t.id
              WHERE t.hand_id = ? ORDER BY t.trick_number, p.play_seq'
        );
        $log->execute([$handId]);
        $out['playLog'] = array_map(fn($r) => [
            'trick'  => (int)$r['trick_number'],
            'winner' => $r['winner_seat'] !== null ? (int)$r['winner_seat'] : null,
            'seat'   => (int)$r['seat'],
            'card' => ( (int)($r['face_down'] ?? 0) === 1
            && $r['winner_seat'] === null
            && (int)$r['seat'] !== $viewerSeat )
            ? null
            : (int)$r['card'],
        ], $log->fetchAll());

		// ---- bidding log (drives client-side bid reveal) ----
        $blog = $pdo->prepare('SELECT seq, seat, bid FROM bids WHERE hand_id = ? ORDER BY seq');
        $blog->execute([$handId]);
        $out['bidLog'] = array_map(fn($r) => [
            'seq'  => (int)$r['seq'],
            'seat' => (int)$r['seat'],
            'bid'  => $r['bid'],
        ], $blog->fetchAll());
		
		// ---- game-end info ----
        $out['maxHands'] = $game['max_hands'] !== null ? (int)$game['max_hands'] : null;
        $out['handsPlayed'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM hands WHERE game_id=$gameId AND status='scored'")->fetchColumn();
		$out['targetScore'] = $game['target_score'] !== null ? (int)$game['target_score'] : null;

        if ($game['status'] === 'finished') {
            $rows = $pdo->query("SELECT seat, display_name, seat_type, score
                                 FROM game_seats WHERE game_id=$gameId ORDER BY score DESC, seat")->fetchAll();
            $top = $rows ? (int)$rows[0]['score'] : 0;
            $out['finalStandings'] = array_map(fn($r)=>[
                'seat'=>(int)$r['seat'], 'name'=>$r['display_name'],
                'type'=>$r['seat_type'], 'score'=>(int)$r['score'],
                'winner'=>((int)$r['score']===$top),
            ], $rows);
        }

        return $out;
    }
}
