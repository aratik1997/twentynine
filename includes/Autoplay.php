<?php
declare(strict_types=1);

/**
 * When a seated player leaves or is kicked mid-round, the round must
 * still be able to finish so the table isn't stuck forever. This walks
 * the current turn forward, auto-passing/auto-selecting/auto-playing on
 * behalf of any seat whose occupant is no longer 'active' — or who has
 * simply taken longer than TURN_TIMEOUT_SECONDS to act — until either
 * play reaches a live player (within their time budget) or the round ends.
 *
 * $activeSeatPlayerIds: seat => player_id for seats occupied by players
 * whose game_players.status = 'active'. Any seat NOT in this map is
 * treated as absent and auto-resolved.
 */
function resolve_absent_turns(array $game, array $state, array $activeSeatPlayerIds): array
{
    $guard = 0;
    while ($guard++ < 64) {
        if (!in_array($state['phase'], ['bidding', 'trump_select', 'playing'], true)) {
            break;
        }
        $turnSeat = $state['turn_seat'];
        if ($turnSeat === null) {
            break; // no turn pending
        }
        $isAbsent = !isset($activeSeatPlayerIds[$turnSeat]);
        $timedOut = !$isAbsent && $state['turn_started_at'] !== null
            && (time() - $state['turn_started_at']) >= TURN_TIMEOUT_SECONDS;
        if (!$isAbsent && !$timedOut) {
            break; // live player's turn, still within their time budget
        }

        if ($state['phase'] === 'bidding') {
            $state = GameEngine::passBid($game, $state, $turnSeat);
        } elseif ($state['phase'] === 'trump_select') {
            if ($state['trump_suit'] === null) {
                $hand = $state['hands'][$turnSeat] ?? [];
                $suit = $hand ? Cards::suitOf($hand[0]) : Cards::SUITS[array_rand(Cards::SUITS)];
                $state = GameEngine::selectTrump($game, $state, $turnSeat, $suit);
            } else {
                // Trump's already picked (this same absent/timed-out seat is
                // the bidder) — nothing left to wait on, so move straight to
                // dealing the second batch and starting play.
                $state = GameEngine::proceedToPlay($game, $state, $turnSeat);
            }
        } elseif ($state['phase'] === 'playing') {
            $legal = GameEngine::legalCards($state, $turnSeat);
            if (empty($legal)) {
                break; // shouldn't happen, but avoid an infinite loop
            }
            $card = $legal[array_rand($legal)];
            $state = GameEngine::playCard($game, $state, $turnSeat, $card);
        }
    }
    return $state;
}
