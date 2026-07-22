<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

api_guard(function () {
    $player = require_player();
    $in = json_input();
    $gameId = (int)($in['game_id'] ?? 0);
    $message = trim((string)($in['message'] ?? ''));
    if ($message === '') {
        throw new RuntimeException('Empty message.');
    }
    $message = mb_substr($message, 0, 300);

    $pdo = db();
    $membership = my_membership($gameId, (int)$player['id']);
    if (!$membership || $membership['status'] !== 'active') {
        throw new RuntimeException('You are not at this table.');
    }

    post_chat($pdo, $gameId, (int)$player['id'], $player['nickname'], $message, false);
    json_response(['ok' => true]);
});
