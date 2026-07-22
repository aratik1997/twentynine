<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

api_guard(function () {
    $player = require_player();
    $in = json_input();
    $variant = ($in['variant'] ?? '4p') === '3p' ? '3p' : '4p';
    $name = clean_nickname((string)($in['name'] ?? ($player['nickname'] . "'s Table")));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        do {
            $code = gen_room_code();
            $stmt = $pdo->prepare('SELECT 1 FROM games WHERE code = ?');
            $stmt->execute([$code]);
        } while ($stmt->fetchColumn());

        $settings = default_settings($variant);
        $stmt = $pdo->prepare('INSERT INTO games (code, name, variant, host_player_id, status, settings_json) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$code, $name, $variant, $player['id'], 'lobby', json_encode($settings)]);
        $gameId = (int)$pdo->lastInsertId();

        $pdo->prepare('INSERT INTO game_players (game_id, player_id, seat, team, is_host, role, status) VALUES (?,?,0,0,1,\'player\',\'active\')')
            ->execute([$gameId, $player['id']]);

        $pdo->prepare("INSERT INTO game_state (game_id, phase) VALUES (?, 'waiting')")->execute([$gameId]);

        post_chat($pdo, $gameId, null, 'System', $player['nickname'] . ' created the table.', true);

        $pdo->commit();
        json_response(['ok' => true, 'game_id' => $gameId, 'code' => $code]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});
