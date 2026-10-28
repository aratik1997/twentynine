<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/Bot.php';

api_guard(function () {
    $player = require_player();
    $in = json_input();
    $gameId = (int)($in['game_id'] ?? 0);
    $wantSeat = array_key_exists('seat', $in) ? (int)$in['seat'] : null;
    $difficulty = strtolower((string)($in['difficulty'] ?? 'medium'));
    if (!in_array($difficulty, Bot::DIFFICULTIES, true)) {
        throw new RuntimeException('Bot difficulty must be easy, medium or hard.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        [$game] = load_locked_context($pdo, $gameId);
        $membership = require_membership($pdo, $gameId, (int)$player['id']);
        require_host($game, $membership);

        if ($game['status'] !== 'lobby') {
            throw new RuntimeException('Bots can only be added while the table is in the lobby.');
        }

        $neededSeats = $game['variant'] === '3p' ? 3 : 4;
        $stmt = $pdo->prepare("SELECT seat FROM game_players WHERE game_id = ? AND status = 'active' AND seat IS NOT NULL");
        $stmt->execute([$gameId]);
        $taken = array_map('intval', array_column($stmt->fetchAll(), 'seat'));

        $freeSeat = null;
        if ($wantSeat !== null) {
            if ($wantSeat < 0 || $wantSeat >= $neededSeats) {
                throw new RuntimeException('Invalid seat.');
            }
            if (in_array($wantSeat, $taken, true)) {
                throw new RuntimeException('That seat is already taken.');
            }
            $freeSeat = $wantSeat;
        } else {
            for ($s = 0; $s < $neededSeats; $s++) {
                if (!in_array($s, $taken, true)) {
                    $freeSeat = $s;
                    break;
                }
            }
            if ($freeSeat === null) {
                throw new RuntimeException('No open seats.');
            }
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM game_players WHERE game_id = ? AND is_bot = 1");
        $stmt->execute([$gameId]);
        $botNumber = (int)$stmt->fetchColumn() + 1;
        $nickname = 'Bot ' . $botNumber;

        $colors = ['#4a7c59', '#c0392b', '#2980b9', '#8e44ad', '#d68910', '#16a085'];
        $color = $colors[array_rand($colors)];
        $uuid = 'bot-' . gen_uuid();
        $pdo->prepare('INSERT INTO players (uuid, nickname, avatar_color) VALUES (?, ?, ?)')
            ->execute([$uuid, $nickname, $color]);
        $botPlayerId = (int)$pdo->lastInsertId();

        $team = $game['variant'] === '4p' ? $freeSeat % 2 : null;
        $pdo->prepare('INSERT INTO game_players (game_id, player_id, seat, team, is_host, role, status, is_bot, bot_difficulty) VALUES (?,?,?,?,0,\'player\',\'active\',1,?)')
            ->execute([$gameId, $botPlayerId, $freeSeat, $team, $difficulty]);

        post_chat($pdo, $gameId, null, 'System', $nickname . ' joined the table.', true);

        $pdo->commit();
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});
