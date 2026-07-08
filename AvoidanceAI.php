<?php
declare(strict_types=1);
require_once __DIR__ . '/rikken_engine.php';

/**
 * Shared avoidance-contract AI for Misère (target 0) and Piek (target exactly 1).
 * - defencePlay(): full playout with coordinated defenders (for MC rollouts).
 * - pMake(): determinization estimate of success probability.
 * - chooseCard(): single-card decision for LIVE play (bidder or defender).
 * No trump in either contract.
 */
final class AvoidanceAI {

    /* ---------- shared card helpers ---------- */
    private static function legal(array $hand, ?Suit $led): array {
        if ($led === null) return $hand;
        $same = array_values(array_filter($hand, fn(Card $c)=>$c->suit===$led));
        return $same ?: $hand;
    }
    private static function beats(Card $c, Card $best, Suit $led): bool { // no trump
        if ($c->suit === $led && $best->suit !== $led) return true;
        if ($c->suit !== $led) return false;
        return $c->rank > $best->rank;
    }
    private static function minC(array $c): Card { usort($c, fn($a,$b)=>$a->rank<=>$b->rank); return $c[0]; }
    private static function rm(array &$h, Card $card): void { foreach($h as $i=>$x) if($x===$card){array_splice($h,$i,1);return;} }

    /* ================= MISÈRE (target 0) ================= */
    public static function misereDefencePlay(array $hands, int $bidder, int $leader): int {
        $won=0; $lead=$leader; $voidShown=[0=>[],1=>[],2=>[],3=>[]];
        for($t=0;$t<13;$t++){
            $led=null;$best=null;$bestSeat=-1;
            for($s=0;$s<4;$s++){
                $seat=($lead+$s)%4; $legal=self::legal($hands[$seat],$led);
                if($led!==null){ $has=false; foreach($hands[$seat] as $c) if($c->suit===$led){$has=true;break;}
                    if(!$has) $voidShown[$seat][$led->value]=true; }
                $card = ($seat===$bidder)
                    ? self::playToLose($legal,$led,$best)
                    : self::misereDefender($legal,$led,$best,$bestSeat,$bidder,$hands[$seat],$voidShown);
                self::rm($hands[$seat],$card); $led??=$card->suit;
                if($best===null||self::beats($card,$best,$led)){$best=$card;$bestSeat=$seat;}
            }
            if($bestSeat===$bidder)$won++; $lead=$bestSeat;
        }
        return $won;
    }
    private static function playToLose(array $legal, ?Suit $led, ?Card $best): Card {
        if($led===null) return self::minC($legal);
        $los=array_values(array_filter($legal,fn(Card $c)=>!self::beats($c,$best,$led)));
        if($los){usort($los,fn($a,$b)=>$b->rank<=>$a->rank);return $los[0];}
        return self::minC($legal);
    }
    private static function misereDefender(array $legal,?Suit $led,?Card $best,int $bestSeat,int $bidder,array $own,array $voidShown):Card{
        if($led===null){
            $g=HandEvaluator::bySuit($own); $bs=null;$bl=-1;
            foreach([0,1,2,3] as $sv){$len=count($g[$sv]); if($len===0)continue;
                $pen=!empty($voidShown[$bidder][$sv])?100:0; $sc=$len-$pen; if($sc>$bl){$bl=$sc;$bs=$sv;}}
            $in=array_values(array_filter($legal,fn(Card $c)=>$c->suit->value===$bs));
            return self::minC($in?:$legal);
        }
        return self::playToLose($legal,$led,$best);
    }

    /* ================= PIEK (target exactly 1) ================= */
    public static function piekDefencePlay(array $hands, int $bidder, int $leader): int {
        $won=0;$lead=$leader;$bw=0;
        for($t=0;$t<13;$t++){
            $led=null;$best=null;$bestSeat=-1;
            for($s=0;$s<4;$s++){
                $seat=($lead+$s)%4;$legal=self::legal($hands[$seat],$led);
                $card=($seat===$bidder)
                    ? self::piekBidderPlay($legal,$led,$best,$bw)
                    : self::piekDefender($legal,$led,$best,$bestSeat,$bidder,($bw===0)?'deny':'force');
                self::rm($hands[$seat],$card);$led??=$card->suit;
                if($best===null||self::beats($card,$best,$led)){$best=$card;$bestSeat=$seat;}
            }
            if($bestSeat===$bidder){$won++;$bw++;}$lead=$bestSeat;
        }
        return $won;
    }
    private static function piekBidderPlay(array $legal,?Suit $led,?Card $best,int $bw):Card{
        if($bw>=1){ if($led===null)return self::minC($legal);
            $los=array_values(array_filter($legal,fn(Card $c)=>!self::beats($c,$best,$led)));
            if($los){usort($los,fn($a,$b)=>$b->rank<=>$a->rank);return $los[0];} return self::minC($legal);}
        if($led===null){ usort($legal,fn($a,$b)=>$a->rank<=>$b->rank); return $legal[intdiv(count($legal),2)]; }
        $win=array_values(array_filter($legal,fn(Card $c)=>self::beats($c,$best,$led)));
        if($win){usort($win,fn($a,$b)=>$a->rank<=>$b->rank);return $win[0];} return self::minC($legal);
    }
    private static function piekDefender(array $legal,?Suit $led,?Card $best,int $bestSeat,int $bidder,string $phase):Card{
        if($led===null)return self::minC($legal);
        $bidderWinning=($bestSeat===$bidder);
        if($phase==='deny'){
            if($bidderWinning){$win=array_values(array_filter($legal,fn(Card $c)=>self::beats($c,$best,$led)));
                if($win){usort($win,fn($a,$b)=>$a->rank<=>$b->rank);return $win[0];}}
            return self::minC($legal);
        }
        $los=array_values(array_filter($legal,fn(Card $c)=>!self::beats($c,$best,$led)));
        if($los){usort($los,fn($a,$b)=>$b->rank<=>$a->rank);return $los[0];} return self::minC($legal);
    }

    /* ================= DETERMINIZATION (bid judgment) ================= */
    public static function pMake(string $contract, array $ownCards, int $bidderSeat, int $leader, int $rollouts=40): float {
        $ownIds=array_map(fn(Card $c)=>$c->id(),$ownCards);
        $unknown=[]; for($i=0;$i<52;$i++) if(!in_array($i,$ownIds,true))$unknown[]=$i;
        $ok=0;
        for($r=0;$r<$rollouts;$r++){
            shuffle($unknown);
            $hands=[[],[],[],[]];$hands[$bidderSeat]=$ownCards;$k=0;
            for($s=0;$s<4;$s++){ if($s===$bidderSeat)continue;
                $hands[$s]=array_map(fn($id)=>Card::fromId($id),array_slice($unknown,$k,13));$k+=13;}
            $tr = $contract==='piek'
                ? self::piekDefencePlay([$hands[0],$hands[1],$hands[2],$hands[3]],$bidderSeat,$leader)
                : self::misereDefencePlay([$hands[0],$hands[1],$hands[2],$hands[3]],$bidderSeat,$leader);
            if(($contract==='piek' && $tr===1) || ($contract!=='piek' && $tr===0)) $ok++;
        }
        return $ok/$rollouts;
    }

    /* ================= LIVE single-card decisions ================= */
    /** Choose a card in a live avoidance hand. $bidderWon = tricks bidder has so far. */
    public static function chooseCard(string $contract, array $legalIds, ?int $ledSuit,
                                      array $trickPlays, int $seat, int $bidder, int $bidderWon): int {
        $legal = array_map(fn($id)=>Card::fromId($id), $legalIds);
        $led = $ledSuit!==null ? Suit::from($ledSuit) : null;
        // reconstruct current best
        $best=null;$bestSeat=-1;
        foreach($trickPlays as $p){ $c=Card::fromId((int)$p['card']);
            if($best===null || self::beats($c,$best,Suit::from((int)$p['seat']===$bestSeat?$led->value:$c->suit->value)===$led? $led:$led,$led??$c->suit)){} }
        // simpler robust recompute:
        $best=null;$bestSeat=-1;$ls=$led;
        foreach($trickPlays as $i=>$p){ $c=Card::fromId((int)$p['card']);
            if($i===0) $ls=Suit::from(intdiv((int)$p['card'],13));
            if($best===null || self::beats($c,$best,$ls)){$best=$c;$bestSeat=(int)$p['seat'];} }
        if($led===null) $ls=null;

        $isBidder = ($seat===$bidder);
        if($contract==='piek'){
            $card = $isBidder ? self::piekBidderPlay($legal,$ls,$best,$bidderWon)
                              : self::piekDefender($legal,$ls,$best,$bestSeat,$bidder,($bidderWon===0)?'deny':'force');
        } else {
            $card = $isBidder ? self::playToLose($legal,$ls,$best)
                              : self::misereDefenderLive($legal,$ls,$best,$bestSeat,$bidder);
        }
        return $card->id();
    }
    /** Live Misère defender without void-tracking (leads low from longest own suit). */
    private static function misereDefenderLive(array $legal,?Suit $led,?Card $best,int $bestSeat,int $bidder):Card{
        if($led===null){
            // lead low from longest suit in the legal set
            $bySuit=[0=>[],1=>[],2=>[],3=>[]]; foreach($legal as $c)$bySuit[$c->suit->value][]=$c;
            $bs=0;$bl=-1; foreach([0,1,2,3] as $sv){ if(count($bySuit[$sv])>$bl){$bl=count($bySuit[$sv]);$bs=$sv;} }
            return self::minC($bySuit[$bs]);
        }
        return self::playToLose($legal,$led,$best);
    }
	
	/* ================= OPEN variants (defenders see bidder hand) ================= */

    /** Full open playout for rollouts. $contract: 'open_misere'|'open_piek'. Declarer leads. */
    public static function openDefencePlay(array $hands, int $bidder, string $contract): int {
		// praatje behaves exactly like open_misere for play/rollout purposes
        if ($contract === 'open_misere_praatje') $contract = 'open_misere';
        $won=0; $lead=$bidder; $bw=0;                     // declarer leads both
        $target=($contract==='open_piek')?1:0;
        for($t=0;$t<13;$t++){
            $led=null;$best=null;$bestSeat=-1;
            for($s=0;$s<4;$s++){
                $seat=($lead+$s)%4;$legal=self::legal($hands[$seat],$led);
                if($seat===$bidder){
                    $card=self::openBidderPlay($legal,$led,$best,$bw,$target);
                }else{
                    $phase=($contract==='open_piek' && $bw===0)?'deny':'force';
                    $card=self::openDefender($legal,$led,$best,$bestSeat,$bidder,$hands[$bidder],$phase);
                }
                self::rm($hands[$seat],$card);$led??=$card->suit;
                if($best===null||self::beats($card,$best,$led)){$best=$card;$bestSeat=$seat;}
            }
            if($bestSeat===$bidder){$won++;$bw++;}$lead=$bestSeat;
        }
        return $won;
    }
    private static function openBidderPlay(array $legal,?Suit $led,?Card $best,int $bw,int $target):Card{
        if($target===1 && $bw<1){
            if($led===null){usort($legal,fn($a,$b)=>$b->rank<=>$a->rank);return $legal[0];}
            $win=array_values(array_filter($legal,fn(Card $c)=>self::beats($c,$best,$led)));
            if($win){usort($win,fn($a,$b)=>$b->rank<=>$a->rank);return $win[0];}
            return self::minC($legal);
        }
        if($led===null)return self::minC($legal);
        $los=array_values(array_filter($legal,fn(Card $c)=>!self::beats($c,$best,$led)));
        if($los){usort($los,fn($a,$b)=>$b->rank<=>$a->rank);return $los[0];}
        return self::minC($legal);
    }
    private static function openDefender(array $legal,?Suit $led,?Card $best,int $bestSeat,int $bidder,array $bidderHand,string $phase):Card{
        if($led===null){ $tg=self::trapSuit($bidderHand);
            $in=array_values(array_filter($legal,fn(Card $c)=>$c->suit->value===$tg)); return self::minC($in?:$legal); }
        $bw=($bestSeat===$bidder);
        if($phase==='deny'){ if($bw){$win=array_values(array_filter($legal,fn(Card $c)=>self::beats($c,$best,$led)));
            if($win){usort($win,fn($a,$b)=>$a->rank<=>$b->rank);return $win[0];}} return self::minC($legal); }
        if($bw)return self::minC($legal);
        $los=array_values(array_filter($legal,fn(Card $c)=>!self::beats($c,$best,$led)));
        if($los){usort($los,fn($a,$b)=>$b->rank<=>$a->rank);return $los[0];} return self::minC($legal);
    }
    private static function trapSuit(array $hand):int{
        $g=HandEvaluator::bySuit($hand);$bs=0;$bss=-1.0;
        foreach([0,1,2,3] as $sv){$r=$g[$sv];if(!$r)continue;
            $low=count(array_filter($r,fn($x)=>$x<=8));$sc=($r[0]-8)/(1+$low);
            if($sc>$bss){$bss=$sc;$bs=$sv;}} return $bs;
    }

    /** Open determinization estimate. */
    public static function pMakeOpen(string $contract, array $own, int $seat, int $rolls=40): float {
		// praatje behaves exactly like open_misere for play/rollout purposes
        if ($contract === 'open_misere_praatje') $contract = 'open_misere';
        $ownIds=array_map(fn(Card $c)=>$c->id(),$own);
        $unk=[];for($i=0;$i<52;$i++)if(!in_array($i,$ownIds,true))$unk[]=$i;
        $target=($contract==='open_piek')?1:0;$ok=0;
        for($r=0;$r<$rolls;$r++){ shuffle($unk);$hands=[[],[],[],[]];$hands[$seat]=$own;$k=0;
            for($s=0;$s<4;$s++){if($s===$seat)continue;
                $hands[$s]=array_map(fn($id)=>Card::fromId($id),array_slice($unk,$k,13));$k+=13;}
            $tr=self::openDefencePlay([$hands[0],$hands[1],$hands[2],$hands[3]],$seat,$contract);
            if($tr===$target)$ok++; }
        return $ok/$rolls;
    }

    /** Live single-card choice for OPEN hands (defenders see bidder's face-up hand). */
    public static function chooseCardOpen(string $contract, array $legalIds, ?int $ledSuit,
                                          array $trickPlays, int $seat, int $bidder, int $bidderWon,
                                          array $bidderHandIds): int {
	// praatje behaves exactly like open_misere for play/rollout purposes
        if ($contract === 'open_misere_praatje') $contract = 'open_misere';
        $legal=array_map(fn($id)=>Card::fromId($id),$legalIds);
        $best=null;$bestSeat=-1;$ls=$ledSuit!==null?Suit::from($ledSuit):null;
        foreach($trickPlays as $i=>$p){ $c=Card::fromId((int)$p['card']);
            if($i===0)$ls=Suit::from(intdiv((int)$p['card'],13));
            if($best===null||self::beats($c,$best,$ls)){$best=$c;$bestSeat=(int)$p['seat'];} }
        if($ledSuit===null && !$trickPlays) $ls=null;
        $target=($contract==='open_piek')?1:0;
        if($seat===$bidder) return self::openBidderPlay($legal,$ls,$best,$bidderWon,$target)->id();
        $bidderHand=array_map(fn($id)=>Card::fromId($id),$bidderHandIds);
        $phase=($contract==='open_piek' && $bidderWon===0)?'deny':'force';
        return self::openDefender($legal,$ls,$best,$bestSeat,$bidder,$bidderHand,$phase)->id();
    }
}