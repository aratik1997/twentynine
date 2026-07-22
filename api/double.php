<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

api_guard(function () {
    $in = json_input();
    $gameId = (int)($in['game_id'] ?? 0);
    run_seat_action($gameId, fn($game, $state, $seat) => GameEngine::callDouble($game, $state, $seat));
});
