<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

api_guard(function () {
    $p = current_player();
    json_response(['ok' => true, 'player' => $p ? [
        'id' => (int)$p['id'], 'uuid' => $p['uuid'], 'nickname' => $p['nickname'], 'avatar_color' => $p['avatar_color'],
    ] : null]);
});
