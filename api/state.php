<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

api_guard(function () {
    $player = require_player();
    $gameId = (int)($_GET['game_id'] ?? 0);
    $sinceChatId = (int)($_GET['since_chat_id'] ?? 0);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        [$game, $state] = load_locked_context($pdo, $gameId);
        $membership = my_membership($gameId, (int)$player['id']);
        if (!$membership) {
            throw new RuntimeException('You are not part of this game.');
        }

        if ($state && in_array($state['phase'], ['bidding', 'trump_select', 'playing'], true)) {
            $activeMap = active_seat_map($pdo, $gameId);
            $before = $state;
            $state = resolve_absent_turns($game, $state, $activeMap, bot_seat_map($pdo, $gameId));
            if ($state !== $before) {
                save_state($pdo, $gameId, $state);
                if ($before['phase'] !== 'round_end' && $state['phase'] === 'round_end') {
                    apply_round_result($pdo, $gameId, $state);
                }
            }
        }

        $stmt = $pdo->prepare('SELECT p.nickname FROM players p WHERE p.id = ?');
        $stmt->execute([$game['host_player_id']]);
        $hostNickname = $stmt->fetchColumn() ?: 'Host';

        $players = array_map(function ($p) {
            return [
                'player_id'    => (int)$p['player_id'],
                'nickname'     => $p['nickname'],
                'avatar_color' => $p['avatar_color'],
                'seat'         => $p['seat'] !== null ? (int)$p['seat'] : null,
                'team'         => $p['team'] !== null ? (int)$p['team'] : null,
                'role'         => $p['role'],
                'is_host'      => (bool)$p['is_host'],
                'is_bot'       => (bool)$p['is_bot'],
                'bot_difficulty' => $p['bot_difficulty'],
                'score'        => (int)$p['score'],
            ];
        }, array_values(array_filter(game_players_of($gameId), fn($p) => $p['status'] === 'active')));

        $mySeat = $membership['seat'] !== null ? (int)$membership['seat'] : null;

        $resp = [
            'ok'   => true,
            'game' => [
                'id' => (int)$game['id'], 'code' => $game['code'], 'name' => $game['name'],
                'variant' => $game['variant'], 'status' => $game['status'],
                'settings' => $game['settings'], 'host_player_id' => (int)$game['host_player_id'],
                'host_nickname' => $hostNickname, 'needed_seats' => GameEngine::numSeats($game),
                'poll_interval_ms' => POLL_INTERVAL_MS,
            ],
            'me' => [
                'player_id' => (int)$player['id'], 'seat' => $mySeat,
                'role' => $membership['role'], 'status' => $membership['status'],
                'is_host' => (bool)$membership['is_host'],
            ],
            'players' => $players,
            'state'   => null,
            'my_hand' => [],
            'legal_cards' => [],
            'other_hand_counts' => [],
        ];

        if ($state) {
            $trumpVisible = $state['trump_revealed'] || ($mySeat !== null && $mySeat === $state['bidder_seat']);
            $handCounts = [];
            foreach ($state['hands'] as $seat => $cards) {
                $handCounts[$seat] = count($cards);
            }

            $resp['state'] = [
                'round_no'        => $state['round_no'],
                'phase'           => $state['phase'],
                'dealer_seat'     => $state['dealer_seat'],
                'turn_seat'       => $state['turn_seat'],
                'turn_seconds_left' => ($state['turn_seat'] !== null && $state['turn_started_at'] !== null
                    && in_array($state['phase'], ['bidding', 'trump_select', 'playing'], true))
                    ? max(0, TURN_TIMEOUT_SECONDS - (time() - $state['turn_started_at']))
                    : null,
                'bid_history'     => $state['bid_history'],
                'highest_bid'     => $state['highest_bid'],
                'bidder_seat'     => $state['bidder_seat'],
                'doubled_by'      => $state['doubled_by'],
                'redoubled_by'    => $state['redoubled_by'],
                'trump_suit'      => $trumpVisible ? $state['trump_suit'] : null,
                'trump_revealed'  => $state['trump_revealed'],
                // Safe to expose to everyone even before reveal — it's just
                // a yes/no, not the suit itself. Lets opponents know the
                // bidder has locked in trump and the Double window is open.
                'trump_chosen'    => $state['trump_suit'] !== null,
                'trick_no'        => $state['trick_no'],
                'trick_lead_seat' => $state['trick_lead_seat'],
                'table_cards'     => $state['table_cards'],
                'trick_history'   => $state['trick_history'],
                'points'          => $state['points'],
                'seat_order'      => $state['seat_order'],
                // marriage_seat is only revealed once actually announced via
                // play (status != 'none') — otherwise it'd spoil who holds
                // King+Queen of trump before they've chosen to show it.
                'marriage_seat'   => $state['marriage_status'] !== 'none' ? $state['marriage_seat'] : null,
                'marriage_status' => $state['marriage_status'],
                'last_result'     => $state['last_result'],
            ];
            $resp['other_hand_counts'] = $handCounts;

            if ($mySeat !== null && isset($state['hands'][$mySeat])) {
                $hand = $state['hands'][$mySeat];
                usort($hand, function ($a, $b) {
                    $sa = Cards::suitOf($a); $sb = Cards::suitOf($b);
                    if ($sa !== $sb) return $sa <=> $sb;
                    return Cards::rankStrength($a) <=> Cards::rankStrength($b);
                });
                $resp['my_hand'] = $hand;
                if ($state['phase'] === 'playing' && $state['turn_seat'] === $mySeat) {
                    $resp['legal_cards'] = GameEngine::legalCards($state, $mySeat);
                }
            }
        }

        $stmt = $pdo->prepare('SELECT id, nickname, message, is_system, UNIX_TIMESTAMP(created_at) AS ts FROM chat_messages WHERE game_id = ? AND id > ? ORDER BY id ASC LIMIT 100');
        $stmt->execute([$gameId, $sinceChatId]);
        $resp['chat'] = $stmt->fetchAll();

        $pdo->commit();
        json_response($resp);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});
