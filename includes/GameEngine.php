<?php
declare(strict_types=1);

require_once __DIR__ . '/Cards.php';

/**
 * Pure game-logic engine for 29. Operates on plain arrays (the decoded
 * game + game_state rows) and returns mutated copies. No DB/IO here —
 * callers (api/*.php) are responsible for persisting the result inside
 * a transaction (SELECT ... FOR UPDATE) so concurrent actions can't race.
 *
 * $seatPlayers: ordered map seat(int) => player_id(int), size 4 (variant
 * '4p') or 3 (variant '3p'). Team for 4p is always `seat % 2` (seats 0/2
 * vs 1/3, i.e. partners sit opposite each other around the table).
 */
final class GameEngine
{
    public const TRICKS_PER_ROUND = 8; // both variants deal 8 cards/hand

    public static function numSeats(array $game): int
    {
        return $game['variant'] === '3p' ? 3 : 4;
    }

    public static function teamOf(array $game, int $seat): ?int
    {
        return $game['variant'] === '3p' ? null : ($seat % 2);
    }

    private static function nextSeat(int $seat, int $numSeats): int
    {
        return ($seat + 1) % $numSeats;
    }

    /** Every turn_seat change resets the 30s-per-turn clock. */
    private static function setTurn(array $state, ?int $seat): array
    {
        $state['turn_seat'] = $seat;
        $state['turn_started_at'] = $seat !== null ? time() : null;
        return $state;
    }

    // ------------------------------------------------------------------
    // Round setup
    // ------------------------------------------------------------------

    /**
     * Deal a fresh round. $seatPlayers must already reflect the (possibly
     * reshuffled) seating for this round.
     */
    public static function startRound(array $game, ?array $prevState, array $seatPlayers): array
    {
        $settings = $game['settings'];
        $numSeats = self::numSeats($game);
        $roundNo = $prevState ? (int)$prevState['round_no'] + 1 : 1;
        $dealerSeat = $prevState && $prevState['dealer_seat'] !== null
            ? self::nextSeat((int)$prevState['dealer_seat'], $numSeats)
            : 0;

        $deck = Cards::fullDeck();
        shuffle($deck);

        $dealStyle = $game['variant'] === '3p' ? ($settings['deal_style'] ?? 'full') : 'phased';

        if ($game['variant'] === '3p') {
            $removeCount = 8;
            for ($i = 0; $i < $removeCount; $i++) {
                array_splice($deck, random_int(0, count($deck) - 1), 1);
            }
        }

        $hands = [];
        foreach (array_keys($seatPlayers) as $seat) {
            $hands[$seat] = [];
        }

        $firstDealCount = $dealStyle === 'full' ? 8 : 4;
        foreach (array_keys($seatPlayers) as $seat) {
            $hands[$seat] = array_splice($deck, 0, $firstDealCount);
        }

        $points = [];
        foreach (array_keys($seatPlayers) as $seat) {
            $points[$seat] = 0;
        }

        return [
            'round_no'           => $roundNo,
            'dealer_seat'        => $dealerSeat,
            'turn_seat'          => self::nextSeat($dealerSeat, $numSeats),
            'turn_started_at'    => time(),
            'phase'              => 'bidding',
            'bid_history'        => [],
            'highest_bid'        => null,
            'bidder_seat'        => null,
            'doubled_by'         => null,
            'redoubled_by'       => null,
            'trump_suit'         => null,
            'trump_revealed'     => false,
            'hands'              => $hands,
            'deck'               => $deck, // leftover, dealt after trump selection (phased style)
            'table_cards'        => [],
            'trick_no'           => 0,
            'trick_lead_seat'    => null,
            'trick_history'      => [],
            'points'             => $points,
            'seat_order'         => $seatPlayers,
            'marriage_seat'      => null,
            'marriage_status'    => 'none',
            'last_result'        => $prevState['last_result'] ?? null,
            'passed_seats'       => [],
            'single_hand_seat'   => null,
        ];
    }

    private static function passedSeats(array $state): array
    {
        $passed = [];
        foreach ($state['bid_history'] as $entry) {
            if ($entry['action'] === 'pass') {
                $passed[$entry['seat']] = true;
            }
        }
        return $passed;
    }

    // ------------------------------------------------------------------
    // Bidding
    // ------------------------------------------------------------------

    public static function placeBid(array $game, array $state, int $seat, int $value): array
    {
        self::assertPhase($state, 'bidding');
        self::assertTurn($state, $seat);

        $settings = $game['settings'];
        $min = $state['highest_bid'] !== null ? $state['highest_bid'] + 1 : (int)$settings['min_bid'];
        $max = (int)$settings['max_bid'];
        if ($value < $min || $value > $max) {
            throw new RuntimeException("Bid must be between $min and $max.");
        }

        $state['bid_history'][] = ['seat' => $seat, 'action' => 'bid', 'value' => $value];
        $state['highest_bid'] = $value;
        $state['bidder_seat'] = $seat;

        return self::advanceBidding($game, $state, $seat);
    }

    public static function passBid(array $game, array $state, int $seat): array
    {
        self::assertPhase($state, 'bidding');
        self::assertTurn($state, $seat);

        $state['bid_history'][] = ['seat' => $seat, 'action' => 'pass', 'value' => null];

        return self::advanceBidding($game, $state, $seat);
    }

    private static function advanceBidding(array $game, array $state, int $justActed): array
    {
        $numSeats = self::numSeats($game);
        $passed = self::passedSeats($state);
        $stillIn = array_filter(range(0, $numSeats - 1), fn($s) => !isset($passed[$s]));

        // Everyone passed, nobody ever bid: dealer is forced to bid the minimum.
        if ($state['highest_bid'] === null && count($passed) === $numSeats) {
            $dealerSeat = (int)$state['dealer_seat'];
            $state['highest_bid'] = (int)$game['settings']['min_bid'];
            $state['bidder_seat'] = $dealerSeat;
            $state['bid_history'][] = ['seat' => $dealerSeat, 'action' => 'forced_bid', 'value' => $state['highest_bid']];
            return self::endBidding($game, $state);
        }

        // A bid stands and everyone else has passed.
        if ($state['highest_bid'] !== null && count($stillIn) <= 1) {
            return self::endBidding($game, $state);
        }

        // Advance turn to the next seat that hasn't passed.
        $next = self::nextSeat($justActed, $numSeats);
        $guard = 0;
        while (isset($passed[$next]) && $guard < $numSeats * 2) {
            $next = self::nextSeat($next, $numSeats);
            $guard++;
        }
        $state = self::setTurn($state, $next);
        return $state;
    }

    private static function endBidding(array $game, array $state): array
    {
        $bidderSeat = (int)$state['bidder_seat'];
        if (!empty($game['settings']['auto_trump'])) {
            $suit = self::bestSuitFor($state['hands'][$bidderSeat]);
            $state['trump_suit'] = $suit;
            $state['phase'] = 'playing';
            $state = self::dealSecondBatch($game, $state);
            if (self::teamMissingTrump($game, $state) !== null) {
                $state = self::voidRoundForNoTrump($game, $state);
            }
        } else {
            $state['phase'] = 'trump_select';
            $state = self::setTurn($state, $bidderSeat);
        }
        return $state;
    }

    private static function bestSuitFor(array $hand): string
    {
        $counts = ['S' => 0, 'H' => 0, 'D' => 0, 'C' => 0];
        $values = ['S' => 0, 'H' => 0, 'D' => 0, 'C' => 0];
        foreach ($hand as $card) {
            $s = Cards::suitOf($card);
            $counts[$s]++;
            $values[$s] += Cards::pointValue($card);
        }
        arsort($counts);
        $top = array_key_first($counts);
        $topCount = $counts[$top];
        $ties = array_keys(array_filter($counts, fn($c) => $c === $topCount));
        if (count($ties) > 1) {
            usort($ties, fn($a, $b) => $values[$b] <=> $values[$a]);
            return $ties[0];
        }
        return $top;
    }

    // ------------------------------------------------------------------
    // Trump selection
    // ------------------------------------------------------------------

    /**
     * Sets trump but deliberately does NOT deal the second batch yet — that
     * only happens once the bidder calls proceedToPlay(). This leaves a
     * real window, still in 'trump_select', where opponents can call
     * Double (and the bidder's side Redouble in response) before the
     * remaining 4 cards are dealt.
     */
    public static function selectTrump(array $game, array $state, int $seat, string $suit): array
    {
        self::assertPhase($state, 'trump_select');
        if ($seat !== (int)$state['bidder_seat']) {
            throw new RuntimeException('Only the bidder selects trump.');
        }
        if ($state['trump_suit'] !== null) {
            throw new RuntimeException('Trump has already been selected.');
        }
        if (!in_array($suit, Cards::SUITS, true)) {
            throw new RuntimeException('Invalid suit.');
        }
        $state['trump_suit'] = $suit;
        return $state;
    }

    /**
     * The bidder confirms they're ready to move on: deals the second batch
     * of 4 cards and starts play. This is the actual close of the
     * Double/Redouble window (trump is chosen but the second deal, and
     * therefore play, was deliberately held back until now).
     */
    public static function proceedToPlay(array $game, array $state, int $seat): array
    {
        self::assertPhase($state, 'trump_select');
        if ($seat !== (int)$state['bidder_seat']) {
            throw new RuntimeException('Only the bidder can proceed to play.');
        }
        if ($state['trump_suit'] === null) {
            throw new RuntimeException('Select trump first.');
        }
        $state['phase'] = 'playing';
        $state = self::dealSecondBatch($game, $state);
        if (self::teamMissingTrump($game, $state) !== null) {
            $state = self::voidRoundForNoTrump($game, $state);
        }
        return $state;
    }

    /**
     * 4p only: once both partners' full 8-card hands are known, a
     * partnership holding not a single trump card between them makes for
     * a degenerate deal — the round is voided (redealt from scratch, same
     * round/dealer, no scoring) rather than played out. Returns the void
     * team (0 or 1) or null if the deal is fine.
     */
    private static function teamMissingTrump(array $game, array $state): ?int
    {
        if ($game['variant'] !== '4p' || $state['trump_suit'] === null) {
            return null;
        }
        foreach ([0, 1] as $team) {
            $hasTrump = false;
            foreach (array_keys($state['seat_order']) as $seat) {
                if (self::teamOf($game, (int)$seat) !== $team) {
                    continue;
                }
                foreach ($state['hands'][$seat] as $card) {
                    if (Cards::suitOf($card) === $state['trump_suit']) {
                        $hasTrump = true;
                        break 2;
                    }
                }
            }
            if (!$hasTrump) {
                return $team;
            }
        }
        return null;
    }

    /** Redeal the same round from scratch — same dealer, no rotation, no scoring. */
    private static function voidRoundForNoTrump(array $game, array $state): array
    {
        $seatPlayers = $state['seat_order'];
        $numSeats = self::numSeats($game);
        $dealerSeat = (int)$state['dealer_seat'];
        $redoState = self::startRound($game, $state, $seatPlayers);
        $redoState['round_no'] = $state['round_no'];   // same round, redealt
        $redoState['dealer_seat'] = $dealerSeat;        // same dealer, no rotation
        $redoState = self::setTurn($redoState, self::nextSeat($dealerSeat, $numSeats));
        $redoState['void_no_trump'] = true; // transient flag: the caller posts a chat notice, not persisted
        return $redoState;
    }

    public static function declareSingleHand(array $game, array $state, int $seat): array
    {
        self::assertPhase($state, 'trump_select');
        if (empty($game['settings']['single_hand'])) {
            throw new RuntimeException('Single Hand house rule is disabled.');
        }
        if ($seat !== (int)$state['bidder_seat']) {
            throw new RuntimeException('Only the bidder may declare Single Hand.');
        }
        $state['single_hand_seat'] = $seat;
        return $state;
    }

    private static function dealSecondBatch(array $game, array $state): array
    {
        if (!empty($state['deck'])) {
            foreach (array_keys($state['seat_order']) as $seat) {
                $extra = array_splice($state['deck'], 0, 4);
                $state['hands'][$seat] = array_merge($state['hands'][$seat], $extra);
            }
        }
        $numSeats = self::numSeats($game);
        $dealerSeat = (int)$state['dealer_seat'];
        $lead = self::nextSeat($dealerSeat, $numSeats);
        $state['trick_no'] = 1;
        $state['trick_lead_seat'] = $lead;
        $state = self::setTurn($state, $lead);
        $state['table_cards'] = [];
        return $state;
    }

    // ------------------------------------------------------------------
    // Reveal trump (any seated player, any time mid-play, once)
    // ------------------------------------------------------------------

    public static function revealTrump(array $game, array $state, int $seat): array
    {
        self::assertPhase($state, 'playing');
        if ($state['trump_revealed']) {
            throw new RuntimeException('Trump is already revealed.');
        }
        $state['trump_revealed'] = true;

        // Marriage (King + Queen of trump in one hand) can only be claimed
        // if the holder still has both cards at the moment of reveal —
        // locked in here so a card played earlier (before reveal, when
        // nobody but the bidder knew trump) can't retroactively qualify.
        $king = $state['trump_suit'] . 'K';
        $queen = $state['trump_suit'] . 'Q';
        foreach ($state['hands'] as $handSeat => $hand) {
            if (in_array($king, $hand, true) && in_array($queen, $hand, true)) {
                $state['marriage_seat'] = (int)$handSeat;
                break;
            }
        }

        return $state;
    }

    // ------------------------------------------------------------------
    // Double / Redouble — the window is strictly after the call (bidding
    // has ended) and before the second batch of 4 cards is dealt, i.e.
    // only during 'trump_select'. Once the bidder picks trump the second
    // deal happens immediately and the window is closed for good.
    // ------------------------------------------------------------------

    public static function callDouble(array $game, array $state, int $seat): array
    {
        if (empty($game['settings']['allow_double'])) {
            throw new RuntimeException('Double is disabled in this game.');
        }
        self::assertPhase($state, 'trump_select');
        if (self::teamOf($game, $seat) === self::teamOf($game, (int)$state['bidder_seat'])) {
            throw new RuntimeException('Only opponents of the bidder may call Double.');
        }
        if ($state['doubled_by'] !== null) {
            throw new RuntimeException('Already doubled.');
        }
        $state['doubled_by'] = $seat;
        return $state;
    }

    public static function callRedouble(array $game, array $state, int $seat): array
    {
        if (empty($game['settings']['allow_redouble'])) {
            throw new RuntimeException('Redouble is disabled in this game.');
        }
        self::assertPhase($state, 'trump_select');
        if ($state['doubled_by'] === null) {
            throw new RuntimeException('No double to redouble.');
        }
        if (self::teamOf($game, $seat) !== self::teamOf($game, (int)$state['bidder_seat'])) {
            throw new RuntimeException('Only the bidder\'s side may redouble.');
        }
        if ($state['redoubled_by'] !== null) {
            throw new RuntimeException('Already redoubled.');
        }
        $state['redoubled_by'] = $seat;
        return $state;
    }

    // ------------------------------------------------------------------
    // Play
    // ------------------------------------------------------------------

    public static function legalCards(array $state, int $seat): array
    {
        $hand = $state['hands'][$seat];
        if (empty($state['table_cards'])) {
            return $hand; // leading — any card is legal
        }
        $leadSuit = Cards::suitOf($state['table_cards'][0]['card']);
        $followers = array_values(array_filter($hand, fn($c) => Cards::suitOf($c) === $leadSuit));
        return $followers !== [] ? $followers : $hand;
    }

    public static function playCard(array $game, array $state, int $seat, string $card): array
    {
        self::assertPhase($state, 'playing');
        self::assertTurn($state, $seat);

        $hand = $state['hands'][$seat];
        if (!in_array($card, $hand, true)) {
            throw new RuntimeException('You do not hold that card.');
        }
        $legal = self::legalCards($state, $seat);
        if (!in_array($card, $legal, true)) {
            throw new RuntimeException('You must follow suit.');
        }

        // Remove from hand, place on table.
        $idx = array_search($card, $state['hands'][$seat], true);
        array_splice($state['hands'][$seat], $idx, 1);
        $state['table_cards'][] = ['seat' => $seat, 'card' => $card];

        if ($state['marriage_seat'] === $seat && $state['marriage_status'] !== 'full'
            && $state['trump_suit'] !== null && Cards::suitOf($card) === $state['trump_suit']
            && in_array(Cards::rankOf($card), ['K', 'Q'], true)
        ) {
            $state['marriage_status'] = $state['marriage_status'] === 'none' ? 'half' : 'full';
        }

        $numSeats = self::numSeats($game);
        if (count($state['table_cards']) === $numSeats) {
            $state = self::resolveTrick($game, $state);
        } else {
            $state = self::setTurn($state, self::nextSeat($seat, $numSeats));
        }

        return $state;
    }

    private static function resolveTrick(array $game, array $state): array
    {
        $leadSuit = Cards::suitOf($state['table_cards'][0]['card']);
        $trump = $state['trump_revealed'] ? $state['trump_suit'] : null;
        $winnerIdx = Cards::winner($state['table_cards'], $leadSuit, $trump);
        $winnerSeat = (int)$state['table_cards'][$winnerIdx]['seat'];

        $trickPoints = 0;
        foreach ($state['table_cards'] as $entry) {
            $trickPoints += Cards::pointValue($entry['card']);
        }
        // Classic variant: last trick worth +1 bonus point (28 -> 29 total).
        $totalTricks = count($state['trick_history']) + 1;
        $isLastTrick = $totalTricks === self::TRICKS_PER_ROUND;
        if ($isLastTrick && !empty($game['settings']['last_trick_bonus'])) {
            $trickPoints += 1;
        }

        $state['points'][$winnerSeat] = ($state['points'][$winnerSeat] ?? 0) + $trickPoints;
        $state['trick_history'][] = [
            'trick_no' => $state['trick_no'],
            'cards'    => $state['table_cards'],
            'winner'   => $winnerSeat,
            'points'   => $trickPoints,
        ];
        $state['table_cards'] = [];
        $state['trick_no'] += 1;
        $state = self::setTurn($state, $winnerSeat);
        $state['trick_lead_seat'] = $winnerSeat;

        if ($isLastTrick) {
            $state['phase'] = 'round_end';
            $state = self::finishRound($game, $state);
        }

        return $state;
    }

    // ------------------------------------------------------------------
    // Round finish + scoring
    // ------------------------------------------------------------------

    public static function finishRound(array $game, array $state): array
    {
        $settings = $game['settings'];
        $bidderSeat = (int)$state['bidder_seat'];
        $rawBid = (int)$state['highest_bid'];
        $mult = $state['redoubled_by'] !== null ? 4 : ($state['doubled_by'] !== null ? 2 : 1);

        // Marriage (King+Queen of trump), once fully played, adjusts the
        // call: -4 (floor 16) if held by the bidder's own side, +4 (cap 28)
        // if held by the opposing side.
        $bidValue = $rawBid;
        $marriageSide = null;
        if ($state['marriage_status'] === 'full' && $state['marriage_seat'] !== null) {
            $marriageSeat = (int)$state['marriage_seat'];
            $onBiddingSide = $game['variant'] === '3p'
                ? $marriageSeat === $bidderSeat
                : self::teamOf($game, $marriageSeat) === self::teamOf($game, $bidderSeat);
            $marriageSide = $onBiddingSide ? 'bidder' : 'opponent';
            $bidValue = $onBiddingSide ? max(16, $rawBid - 4) : min(28, $rawBid + 4);
        }

        $scoreDelta = [];
        foreach (array_keys($state['seat_order']) as $seat) {
            $scoreDelta[$seat] = 0;
        }

        if ($game['variant'] === '4p') {
            $bidderTeam = self::teamOf($game, $bidderSeat);
            $teamPoints = [0 => 0, 1 => 0];
            foreach ($state['points'] as $seat => $pts) {
                $teamPoints[self::teamOf($game, (int)$seat)] += $pts;
            }
            $success = $teamPoints[$bidderTeam] >= $bidValue;

            if ($settings['scoring_mode'] === 'marks') {
                $delta = $success ? 1 * $mult : -1 * $mult;
            } else {
                $delta = $success ? $bidValue * $mult : -$bidValue * $mult;
            }
            foreach (array_keys($state['seat_order']) as $seat) {
                if (self::teamOf($game, (int)$seat) === $bidderTeam) {
                    $scoreDelta[$seat] = $delta;
                }
            }

            $state['last_result'] = [
                'bidder_seat'    => $bidderSeat,
                'bid_value'      => $bidValue,
                'raw_bid'        => $rawBid,
                'marriage_seat'  => $state['marriage_seat'],
                'marriage_side'  => $marriageSide,
                'team_points'    => $teamPoints,
                'success'        => $success,
                'multiplier'     => $mult,
                'score_delta'    => $scoreDelta,
            ];
        } else { // 3p
            $bidderPoints = $state['points'][$bidderSeat] ?? 0;
            $success = $bidderPoints >= $bidValue;

            if ($settings['scoring_mode'] === 'marks') {
                $scoreDelta[$bidderSeat] = $success ? 1 * $mult : -1 * $mult;
            } else {
                $bonus = 10; // configurable-variant bonus for a successful bid, on top of tricks won
                $scoreDelta[$bidderSeat] = ($success ? $bonus * $mult : -$bidValue * $mult);
                foreach (array_keys($state['seat_order']) as $seat) {
                    $scoreDelta[$seat] += (int)($state['points'][$seat] ?? 0);
                }
            }

            $state['last_result'] = [
                'bidder_seat'    => $bidderSeat,
                'bid_value'      => $bidValue,
                'raw_bid'        => $rawBid,
                'marriage_seat'  => $state['marriage_seat'],
                'marriage_side'  => $marriageSide,
                'points'         => $state['points'],
                'success'        => $success,
                'multiplier'     => $mult,
                'score_delta'    => $scoreDelta,
                'ranking'        => self::rankSeats($state['points']),
            ];
        }

        $state['phase'] = 'round_end';
        return $state;
    }

    private static function rankSeats(array $points): array
    {
        $seats = array_keys($points);
        usort($seats, fn($a, $b) => $points[$b] <=> $points[$a]);
        return $seats;
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    private static function assertPhase(array $state, string $phase): void
    {
        if ($state['phase'] !== $phase) {
            throw new RuntimeException("Action not allowed during phase '{$state['phase']}'.");
        }
    }

    private static function assertTurn(array $state, int $seat): void
    {
        if ((int)$state['turn_seat'] !== $seat) {
            throw new RuntimeException('It is not your turn.');
        }
    }
}
