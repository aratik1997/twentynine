<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

api_guard(function () {
    $in = json_input();
    $nickname = clean_nickname((string)($in['nickname'] ?? ''));

    // Reuse existing identity if the cookie already maps to one; otherwise
    // create a new guest player. Renaming an existing identity is allowed.
    $existing = current_player();
    if ($existing) {
        db()->prepare('UPDATE players SET nickname = ? WHERE id = ?')->execute([$nickname, $existing['id']]);
        $existing['nickname'] = $nickname;
        json_response(['ok' => true, 'player' => [
            'id' => (int)$existing['id'], 'uuid' => $existing['uuid'], 'nickname' => $nickname, 'avatar_color' => $existing['avatar_color'],
        ]]);
    }

    $p = current_player($nickname);
    json_response(['ok' => true, 'player' => [
        'id' => (int)$p['id'], 'uuid' => $p['uuid'], 'nickname' => $p['nickname'], 'avatar_color' => $p['avatar_color'],
    ]]);
});
