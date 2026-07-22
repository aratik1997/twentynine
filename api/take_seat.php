<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

api_guard(function () {
    $player = require_player();
    $in = json_input();
    $gameId = (int)($in['game_id'] ?? 0);
    $seat = (int)($in['seat'] ?? -1);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        [$game] = load_locked_context($pdo, $gameId);
        $membership = require_membership($pdo, $gameId, (int)$player['id']);

        if ($game['status'] !== 'lobby') {
            throw new RuntimeException('Seats can only be chosen while the table is in the lobby.');
        }

        $neededSeats = $game['variant'] === '3p' ? 3 : 4;
        if ($seat < 0 || $seat >= $neededSeats) {
            throw new RuntimeException('Invalid seat.');
        }
        if ($membership['seat'] === $seat) {
            $pdo->commit();
            json_response(['ok' => true]);
            return;
        }

        $stmt = $pdo->prepare("SELECT 1 FROM game_players WHERE game_id = ? AND status = 'active' AND seat = ? AND player_id != ?");
        $stmt->execute([$gameId, $seat, $player['id']]);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('That seat is already taken.');
        }

        $team = $game['variant'] === '4p' ? $seat % 2 : null;
        $pdo->prepare("UPDATE game_players SET seat = ?, team = ?, role = 'player' WHERE id = ?")
            ->execute([$seat, $team, $membership['id']]);

        post_chat($pdo, $gameId, null, 'System', $player['nickname'] . ' sat at seat ' . ($seat + 1) . '.', true);

        $pdo->commit();
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});
