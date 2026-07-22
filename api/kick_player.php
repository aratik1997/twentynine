<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

api_guard(function () {
    $player = require_player();
    $in = json_input();
    $gameId = (int)($in['game_id'] ?? 0);
    $targetPlayerId = (int)($in['target_player_id'] ?? 0);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        [$game, $state] = load_locked_context($pdo, $gameId);
        $membership = require_membership($pdo, $gameId, (int)$player['id']);
        require_host($game, $membership);

        if ($targetPlayerId === (int)$player['id']) {
            throw new RuntimeException('You cannot kick yourself.');
        }

        $stmt = $pdo->prepare('SELECT gp.*, p.nickname FROM game_players gp JOIN players p ON p.id = gp.player_id WHERE gp.game_id = ? AND gp.player_id = ?');
        $stmt->execute([$gameId, $targetPlayerId]);
        $target = $stmt->fetch();
        if (!$target || $target['status'] !== 'active') {
            throw new RuntimeException('That player is not at the table.');
        }

        $pdo->prepare("UPDATE game_players SET status = 'kicked' WHERE id = ?")->execute([$target['id']]);
        if ($game['status'] === 'lobby' && $target['seat'] !== null) {
            $pdo->prepare('UPDATE game_players SET seat = NULL, team = NULL WHERE id = ?')->execute([$target['id']]);
        }

        post_chat($pdo, $gameId, null, 'System', $player['nickname'] . ' kicked ' . $target['nickname'] . '.', true);
        // Client polling as `target` sees membership.status === 'kicked' in
        // its own state response and renders "(Host name) kicked you".

        if ($state && in_array($state['phase'], ['bidding', 'trump_select', 'playing'], true)) {
            $game['settings'] = json_decode($game['settings_json'], true) ?: $game['settings'];
            $activeMap = active_seat_map($pdo, $gameId);
            $before = $state['phase'];
            $state = resolve_absent_turns($game, $state, $activeMap);
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
