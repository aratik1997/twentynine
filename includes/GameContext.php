<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/GameEngine.php';
require_once __DIR__ . '/Autoplay.php';

/**
 * Loads a game + its live state (row-locked, inside a transaction the
 * caller must have already started) as plain arrays ready for GameEngine.
 */
function load_locked_context(PDO $pdo, int $gameId): array
{
    $stmt = $pdo->prepare('SELECT * FROM games WHERE id = ? FOR UPDATE');
    $stmt->execute([$gameId]);
    $game = $stmt->fetch();
    if (!$game) {
        throw new RuntimeException('Game not found.');
    }
    $game['settings'] = json_decode($game['settings_json'], true) ?: default_settings($game['variant']);

    $stmt = $pdo->prepare('SELECT * FROM game_state WHERE game_id = ? FOR UPDATE');
    $stmt->execute([$gameId]);
    $row = $stmt->fetch();
    $state = null;
    if ($row) {
        $state = [
            'round_no'         => (int)$row['round_no'],
            'dealer_seat'      => $row['dealer_seat'] !== null ? (int)$row['dealer_seat'] : null,
            'turn_seat'        => $row['turn_seat'] !== null ? (int)$row['turn_seat'] : null,
            'turn_started_at'  => $row['turn_started_at'] !== null ? (int)$row['turn_started_at'] : null,
            'phase'            => $row['phase'],
            'bid_history'      => json_decode($row['bid_history_json'] ?? '[]', true) ?: [],
            'highest_bid'      => $row['highest_bid'] !== null ? (int)$row['highest_bid'] : null,
            'bidder_seat'      => $row['bidder_seat'] !== null ? (int)$row['bidder_seat'] : null,
            'doubled_by'       => $row['doubled_by'] !== null ? (int)$row['doubled_by'] : null,
            'redoubled_by'     => $row['redoubled_by'] !== null ? (int)$row['redoubled_by'] : null,
            'trump_suit'       => $row['trump_suit'],
            'trump_revealed'   => (bool)$row['trump_revealed'],
            'hands'            => normalize_seat_map(json_decode($row['hands_json'] ?? '{}', true) ?: []),
            'deck'             => json_decode($row['deck_json'] ?? '[]', true) ?: [],
            'table_cards'      => json_decode($row['table_cards_json'] ?? '[]', true) ?: [],
            'trick_no'         => (int)$row['trick_no'],
            'trick_lead_seat'  => $row['trick_lead_seat'] !== null ? (int)$row['trick_lead_seat'] : null,
            'trick_history'    => json_decode($row['trick_history_json'] ?? '[]', true) ?: [],
            'points'           => normalize_seat_map(json_decode($row['points_json'] ?? '{}', true) ?: []),
            'seat_order'       => normalize_seat_map(json_decode($row['seat_order_json'] ?? '{}', true) ?: []),
            'marriage_seat'    => $row['marriage_seat'] !== null ? (int)$row['marriage_seat'] : null,
            'marriage_status'  => $row['marriage_status'] ?? 'none',
            'last_result'      => json_decode($row['last_result_json'] ?? 'null', true),
            'passed_seats'     => [],
            'single_hand_seat' => null,
            '_version'         => (int)$row['version'],
        ];
    }

    return [$game, $state];
}

/** JSON object keys decode as strings; engine code indexes by int. */
function normalize_seat_map(array $map): array
{
    $out = [];
    foreach ($map as $k => $v) {
        $out[(int)$k] = $v;
    }
    ksort($out);
    return $out;
}

function state_exists(PDO $pdo, int $gameId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM game_state WHERE game_id = ?');
    $stmt->execute([$gameId]);
    return (bool)$stmt->fetchColumn();
}

function save_state(PDO $pdo, int $gameId, array $state, bool $insert = false): void
{
    $cols = [
        'round_no'         => $state['round_no'],
        'dealer_seat'      => $state['dealer_seat'],
        'turn_seat'        => $state['turn_seat'],
        'turn_started_at'  => $state['turn_started_at'],
        'phase'            => $state['phase'],
        'bid_history_json' => json_encode(array_values($state['bid_history'])),
        'highest_bid'      => $state['highest_bid'],
        'bidder_seat'      => $state['bidder_seat'],
        'doubled_by'       => $state['doubled_by'],
        'redoubled_by'     => $state['redoubled_by'],
        'trump_suit'       => $state['trump_suit'],
        'trump_revealed'   => $state['trump_revealed'] ? 1 : 0,
        'hands_json'       => json_encode($state['hands']),
        'deck_json'        => json_encode(array_values($state['deck'])),
        'table_cards_json' => json_encode(array_values($state['table_cards'])),
        'trick_no'         => $state['trick_no'],
        'trick_lead_seat'  => $state['trick_lead_seat'],
        'trick_history_json' => json_encode(array_values($state['trick_history'])),
        'points_json'      => json_encode($state['points']),
        'seat_order_json'  => json_encode($state['seat_order']),
        'marriage_seat'    => $state['marriage_seat'],
        'marriage_status'  => $state['marriage_status'],
        'last_result_json' => json_encode($state['last_result']),
    ];

    if ($insert) {
        $sql = 'INSERT INTO game_state (game_id, ' . implode(',', array_keys($cols)) . ')
                VALUES (?, ' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $pdo->prepare($sql)->execute(array_merge([$gameId], array_values($cols)));
        return;
    }

    $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols))) . ', version = version + 1';
    $sql = "UPDATE game_state SET $set WHERE game_id = ?";
    $pdo->prepare($sql)->execute(array_merge(array_values($cols), [$gameId]));
}

function save_game_settings(PDO $pdo, int $gameId, array $settings): void
{
    $pdo->prepare('UPDATE games SET settings_json = ? WHERE id = ?')
        ->execute([json_encode($settings), $gameId]);
}

function set_game_status(PDO $pdo, int $gameId, string $status): void
{
    $pdo->prepare('UPDATE games SET status = ? WHERE id = ?')->execute([$status, $gameId]);
}

function post_chat(PDO $pdo, int $gameId, ?int $playerId, string $nickname, string $message, bool $system = false): void
{
    $pdo->prepare('INSERT INTO chat_messages (game_id, player_id, nickname, message, is_system) VALUES (?,?,?,?,?)')
        ->execute([$gameId, $playerId, $nickname, mb_substr($message, 0, 500), $system ? 1 : 0]);
}

/**
 * After a round transitions into 'round_end' this applies score deltas
 * to game_players and appends the round_history ledger row. Safe to call
 * only once per transition (callers check phase before/after).
 */
function apply_round_result(PDO $pdo, int $gameId, array $state): void
{
    $result = $state['last_result'];
    if (!$result) {
        return;
    }
    foreach ($result['score_delta'] as $seat => $delta) {
        if ($delta === 0) {
            continue;
        }
        $playerId = $state['seat_order'][$seat] ?? null;
        if ($playerId === null) {
            continue;
        }
        $pdo->prepare('UPDATE game_players SET score = score + ? WHERE game_id = ? AND player_id = ?')
            ->execute([$delta, $gameId, $playerId]);
    }

    $scoresAfter = [];
    $stmt = $pdo->prepare('SELECT seat, score FROM game_players WHERE game_id = ? AND seat IS NOT NULL');
    $stmt->execute([$gameId]);
    foreach ($stmt->fetchAll() as $r) {
        $scoresAfter[$r['seat']] = (int)$r['score'];
    }

    $pdo->prepare(
        'INSERT INTO round_history (game_id, round_no, dealer_seat, bidder_seat, bid_value, trump_suit, doubled, redoubled, result, points_json, score_delta_json, scores_after_json)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $gameId,
        $state['round_no'],
        $state['dealer_seat'],
        $state['bidder_seat'],
        $state['highest_bid'],
        $state['trump_suit'],
        $state['doubled_by'] !== null ? 1 : 0,
        $state['redoubled_by'] !== null ? 1 : 0,
        $result['success'] ? 'success' : 'fail',
        json_encode($result['team_points'] ?? $result['points'] ?? []),
        json_encode($result['score_delta']),
        json_encode($scoresAfter),
    ]);

    set_game_status($pdo, $gameId, 'round_end');
}

/**
 * Shared transaction wrapper for every action that mutates a live round
 * (bid, pass, select trump, play card, double, ...). $mutator receives
 * (game, state, seat, membership) and returns the new state array.
 * Handles locking, seat resolution, absent-player autoplay, scoring on
 * round completion, and the JSON response.
 */
function run_seat_action(int $gameId, callable $mutator): void
{
    $player = require_player();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        [$game, $state] = load_locked_context($pdo, $gameId);
        if (!$state) {
            throw new RuntimeException('Round has not started yet.');
        }
        $membership = require_membership($pdo, $gameId, (int)$player['id']);
        if ($membership['seat'] === null || $membership['role'] !== 'player') {
            throw new RuntimeException('You are spectating — you will join the next round.');
        }
        $seat = (int)$membership['seat'];

        $wasPhase = $state['phase'];
        $state = $mutator($game, $state, $seat, $membership);

        if (!empty($state['void_no_trump'])) {
            post_chat($pdo, $gameId, null, 'System', 'Round voided — a partnership had no trump cards. Redealing round ' . $state['round_no'] . '.', true);
        }

        $activeMap = active_seat_map($pdo, $gameId);
        $state = resolve_absent_turns($game, $state, $activeMap);

        save_state($pdo, $gameId, $state);
        if ($wasPhase !== 'round_end' && $state['phase'] === 'round_end') {
            apply_round_result($pdo, $gameId, $state);
        } elseif ($state['phase'] !== $wasPhase) {
            set_game_status($pdo, $gameId, $state['phase']);
        }

        $pdo->commit();
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
