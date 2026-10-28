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
        [$game, $state] = load_locked_context($pdo, $gameId);
        $membership = require_membership($pdo, $gameId, (int)$player['id']);

        $pdo->prepare("UPDATE game_players SET status = 'left' WHERE id = ?")->execute([$membership['id']]);
        post_chat($pdo, $gameId, null, 'System', $player['nickname'] . ' left the table.', true);

        // If the game was still in the lobby, free the seat immediately so
        // someone else can take it right away.
        if ($game['status'] === 'lobby' && $membership['seat'] !== null) {
            $pdo->prepare('UPDATE game_players SET seat = NULL, team = NULL WHERE id = ?')->execute([$membership['id']]);
        }

        // Reassign host if the host left.
        if ($membership['is_host']) {
            $stmt = $pdo->prepare("SELECT * FROM game_players WHERE game_id = ? AND status = 'active' AND is_bot = 0 AND player_id != ? ORDER BY joined_at ASC LIMIT 1");
            $stmt->execute([$gameId, $player['id']]);
            $next = $stmt->fetch();
            $pdo->prepare('UPDATE game_players SET is_host = 0 WHERE game_id = ?')->execute([$gameId]);
            if ($next) {
                $pdo->prepare('UPDATE game_players SET is_host = 1 WHERE id = ?')->execute([$next['id']]);
                $pdo->prepare('UPDATE games SET host_player_id = ? WHERE id = ?')->execute([$next['player_id'], $gameId]);
                post_chat($pdo, $gameId, null, 'System', $next['nickname'] . ' is now the host.', true);
            }
        }

        // If a round is live, auto-resolve any turns now owned by the
        // departed seat so the table doesn't stall.
        if ($state && in_array($state['phase'], ['bidding', 'trump_select', 'playing'], true)) {
            $game['settings'] = json_decode($game['settings_json'] ?? json_encode($game['settings']), true) ?: $game['settings'];
            $activeMap = active_seat_map($pdo, $gameId);
            $before = $state['phase'];
            $state = resolve_absent_turns($game, $state, $activeMap, bot_seat_map($pdo, $gameId));
            save_state($pdo, $gameId, $state);
            if ($before !== 'round_end' && $state['phase'] === 'round_end') {
                apply_round_result($pdo, $gameId, $state);
            }
        }

        $pdo->commit();
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});
