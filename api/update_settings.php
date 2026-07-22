<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$ALLOWED_KEYS = [
    'min_bid', 'max_bid', 'scoring_mode', 'scoreboard_order', 'target_marks',
    'round_limit', 'allow_double', 'allow_redouble', 'auto_trump', 'single_hand',
    'sound_enabled', 'card_removal', 'deal_style', 'last_trick_bonus', 'trick_reveal_seconds',
];

api_guard(function () use ($ALLOWED_KEYS) {
    $player = require_player();
    $in = json_input();
    $gameId = (int)($in['game_id'] ?? 0);
    $patch = is_array($in['settings'] ?? null) ? $in['settings'] : [];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        [$game] = load_locked_context($pdo, $gameId);
        $membership = require_membership($pdo, $gameId, (int)$player['id']);
        require_host($game, $membership);
        if ($game['status'] !== 'lobby') {
            throw new RuntimeException('Settings can only be changed before the game starts.');
        }

        $settings = $game['settings'];
        foreach ($patch as $key => $value) {
            if (!in_array($key, $ALLOWED_KEYS, true)) {
                continue;
            }
            $settings[$key] = $value;
        }
        $settings['min_bid'] = max(10, min(27, (int)$settings['min_bid']));
        $settings['max_bid'] = max($settings['min_bid'] + 1, min(28, (int)$settings['max_bid']));
        $settings['trick_reveal_seconds'] = max(3, min(10, (int)$settings['trick_reveal_seconds']));

        save_game_settings($pdo, $gameId, $settings);
        post_chat($pdo, $gameId, null, 'System', $player['nickname'] . ' updated the table settings.', true);

        $pdo->commit();
        json_response(['ok' => true, 'settings' => $settings]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});
