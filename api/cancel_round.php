<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * "New Round" button: the host aborts the round currently in progress
 * (bidding/trump/playing) and redeals from scratch with the SAME dealer
 * and round number — nothing scores, it's a full do-over.
 */
api_guard(function () {
    $player = require_player();
    $in = json_input();
    $gameId = (int)($in['game_id'] ?? 0);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        [$game, $state] = load_locked_context($pdo, $gameId);
        if (!$state) {
            throw new RuntimeException('Round has not started yet.');
        }
        $membership = require_membership($pdo, $gameId, (int)$player['id']);
        require_host($game, $membership);
        if (!in_array($state['phase'], ['bidding', 'trump_select', 'playing'], true)) {
            throw new RuntimeException('No round in progress to cancel.');
        }

        $seatPlayers = $state['seat_order'];
        $redoState = GameEngine::startRound($game, $state, $seatPlayers);
        $redoState['round_no'] = $state['round_no'];      // same round, redealt
        $redoState['dealer_seat'] = $state['dealer_seat']; // same dealer, no rotation
        $redoState['turn_seat'] = ($state['dealer_seat'] + 1) % count($seatPlayers);

        save_state($pdo, $gameId, $redoState);
        set_game_status($pdo, $gameId, 'bidding');
        post_chat($pdo, $gameId, null, 'System', $player['nickname'] . ' cancelled the round — redealing.', true);

        $pdo->commit();
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});
