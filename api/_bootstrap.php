<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/GameContext.php';

header('X-Content-Type-Options: nosniff');

function api_guard(callable $fn): void
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        error_out($e->getMessage(), 400);
    } catch (Throwable $e) {
        error_out('Server error: ' . $e->getMessage(), 500);
    }
}

/** Active seat => player_id map for autoplay resolution. Bot seats are
 *  excluded so resolve_absent_turns() always auto-plays their turns. */
function active_seat_map(PDO $pdo, int $gameId): array
{
    $stmt = $pdo->prepare("SELECT seat, player_id FROM game_players WHERE game_id = ? AND status = 'active' AND is_bot = 0 AND seat IS NOT NULL");
    $stmt->execute([$gameId]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[(int)$r['seat']] = (int)$r['player_id'];
    }
    return $map;
}

function require_membership(PDO $pdo, int $gameId, int $playerId): array
{
    $m = my_membership($gameId, $playerId);
    if (!$m || $m['status'] !== 'active') {
        throw new RuntimeException('You are not an active member of this game.');
    }
    return $m;
}

function require_host(array $game, array $membership): void
{
    if (!$membership['is_host']) {
        throw new RuntimeException('Only the host can do that.');
    }
}
