<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

api_guard(function () {
    $player = require_player();
    $in = json_input();
    $code = strtoupper(trim((string)($in['code'] ?? '')));
    if ($code === '') {
        throw new RuntimeException('Enter a table code.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM games WHERE code = ? FOR UPDATE');
        $stmt->execute([$code]);
        $game = $stmt->fetch();
        if (!$game) {
            throw new RuntimeException('No table found with that code.');
        }
        if ($game['status'] === 'cancelled' || $game['status'] === 'finished') {
            throw new RuntimeException('That table has ended.');
        }

        $stmt = $pdo->prepare('SELECT * FROM game_players WHERE game_id = ? AND player_id = ?');
        $stmt->execute([$game['id'], $player['id']]);
        $existing = $stmt->fetch();

        if ($existing && $existing['status'] === 'kicked') {
            throw new RuntimeException('You were removed from this table by the host.');
        }

        // Seats are chosen by the player from the lobby (take_seat.php), not
        // auto-assigned on join — everyone lands as a spectator first.
        if ($existing) {
            $pdo->prepare('UPDATE game_players SET status = "active", last_seen_at = NOW() WHERE id = ?')
                ->execute([$existing['id']]);
            $role = $existing['role'];
        } else {
            $pdo->prepare('INSERT INTO game_players (game_id, player_id, seat, team, is_host, role, status) VALUES (?,?,NULL,NULL,0,\'spectator\',\'active\')')
                ->execute([$game['id'], $player['id']]);
            $role = 'spectator';
        }

        $msg = $game['status'] !== 'lobby'
            ? $player['nickname'] . ' joined to spectate — they will take a seat once this round ends.'
            : $player['nickname'] . ' joined the table.';
        post_chat($pdo, (int)$game['id'], null, 'System', $msg, true);

        $pdo->commit();
        json_response(['ok' => true, 'game_id' => (int)$game['id'], 'code' => $game['code'], 'role' => $role]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});
