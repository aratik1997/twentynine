<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

api_guard(function () {
    $player = require_player();
    $in = json_input();
    $gameId = (int)($in['game_id'] ?? 0);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        [$game] = load_locked_context($pdo, $gameId);
        $membership = require_membership($pdo, $gameId, (int)$player['id']);
        require_host($game, $membership);

        if ($game['status'] !== 'lobby') {
            throw new RuntimeException('This game has already started.');
        }

        $needed = $game['variant'] === '3p' ? 3 : 4;
        $seated = active_seated_players($gameId);
        if (count($seated) < $needed) {
            throw new RuntimeException("Need $needed seated players to start (have " . count($seated) . ').');
        }

        $seatPlayers = [];
        foreach ($seated as $p) {
            $seatPlayers[(int)$p['seat']] = (int)$p['player_id'];
        }
        ksort($seatPlayers);

        $state = GameEngine::startRound($game, null, $seatPlayers);
        $inserted = state_exists($pdo, $gameId);
        save_state($pdo, $gameId, $state, !$inserted);
        set_game_status($pdo, $gameId, 'bidding');
        post_chat($pdo, $gameId, null, 'System', 'Round 1 dealt. Bidding is open.', true);

        $pdo->commit();
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});
