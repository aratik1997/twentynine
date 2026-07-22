<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

/** Appends the file's mtime as a cache-busting query string so browsers
 *  always fetch the latest JS/CSS after a deploy, without needing a
 *  manual hard refresh. */
function asset_url(string $relPath): string
{
    $full = __DIR__ . '/../' . $relPath;
    $v = @filemtime($full) ?: time();
    return $relPath . '?v=' . $v;
}

function json_response($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function error_out(string $message, int $code = 400): void
{
    json_response(['ok' => false, 'error' => $message], $code);
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function gen_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function gen_room_code(): string
{
    // Avoid ambiguous chars (0/O, 1/I).
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

/**
 * Resolve (or create) the guest player identity for this browser via a
 * long-lived cookie. Nickname is required only on first creation.
 */
function current_player(?string $nicknameIfNew = null): ?array
{
    $uuid = $_COOKIE[PLAYER_COOKIE] ?? null;
    $pdo = db();

    if ($uuid) {
        $stmt = $pdo->prepare('SELECT * FROM players WHERE uuid = ?');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch();
        if ($row) {
            $pdo->prepare('UPDATE players SET last_seen_at = NOW() WHERE id = ?')->execute([$row['id']]);
            return $row;
        }
    }

    if ($nicknameIfNew === null) {
        return null;
    }

    $uuid = gen_uuid();
    $colors = ['#4a7c59', '#c0392b', '#2980b9', '#8e44ad', '#d68910', '#16a085'];
    $color = $colors[array_rand($colors)];
    $stmt = $pdo->prepare('INSERT INTO players (uuid, nickname, avatar_color) VALUES (?, ?, ?)');
    $stmt->execute([$uuid, $nicknameIfNew, $color]);
    $id = (int)$pdo->lastInsertId();

    setcookie(PLAYER_COOKIE, $uuid, [
        'expires'  => time() + COOKIE_LIFETIME_DAYS * 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $stmt = $pdo->prepare('SELECT * FROM players WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function require_player(): array
{
    $player = current_player();
    if (!$player) {
        error_out('No player identity. Set a nickname first.', 401);
    }
    return $player;
}

function clean_nickname(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name));
    $name = mb_substr($name, 0, 20);
    return $name === '' ? 'Player' : $name;
}

function game_by_code(string $code): ?array
{
    $stmt = db()->prepare('SELECT * FROM games WHERE code = ?');
    $stmt->execute([strtoupper($code)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function game_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM games WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function game_players_of(int $gameId): array
{
    $stmt = db()->prepare(
        'SELECT gp.*, p.nickname, p.avatar_color, p.uuid
         FROM game_players gp JOIN players p ON p.id = gp.player_id
         WHERE gp.game_id = ? ORDER BY gp.seat IS NULL, gp.seat ASC, gp.joined_at ASC'
    );
    $stmt->execute([$gameId]);
    return $stmt->fetchAll();
}

function active_seated_players(int $gameId): array
{
    return array_values(array_filter(
        game_players_of($gameId),
        fn($p) => $p['status'] === 'active' && $p['role'] === 'player' && $p['seat'] !== null
    ));
}

function my_membership(int $gameId, int $playerId): ?array
{
    $stmt = db()->prepare('SELECT * FROM game_players WHERE game_id = ? AND player_id = ?');
    $stmt->execute([$gameId, $playerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function game_state_of(int $gameId): ?array
{
    $stmt = db()->prepare('SELECT * FROM game_state WHERE game_id = ?');
    $stmt->execute([$gameId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    foreach (['bid_history_json', 'hands_json', 'deck_json', 'table_cards_json', 'trick_history_json', 'points_json', 'seat_order_json', 'last_result_json'] as $col) {
        $row[$col] = $row[$col] !== null ? json_decode($row[$col], true) : null;
    }
    return $row;
}

function default_settings(string $variant): array
{
    return [
        'min_bid'          => 16,
        'max_bid'          => 28,
        'scoring_mode'     => 'marks',      // 'marks' | 'numeric'
        'scoreboard_order' => 'desc',       // 'desc' = highest score on top/wins (standard); 'asc' = lowest on top/wins
        'target_marks'     => 6,            // marks match target for 4p (0 = play indefinitely / round-limited)
        'round_limit'      => 0,            // 0 = unlimited, else stop after N rounds
        'allow_double'     => true,
        'allow_redouble'   => true,
        'auto_trump'       => false,        // skip manual trump pick; engine picks bidder's strongest suit
        'single_hand'      => true,         // allow "single hand" declaration house rule
        'sound_enabled'    => true,
        'card_removal'     => 'random',     // 3p only: 'random' removal of 8 cards before deal
        'trick_reveal_seconds' => 3,        // how long a finished trick stays on the table before being swept away (min 3)
    ];
}
