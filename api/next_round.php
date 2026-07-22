<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

api_guard(function () {
    $player = require_player();
    $in = json_input();
    $gameId = (int)($in['game_id'] ?? 0);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        [$game, $state] = load_locked_context($pdo, $gameId);
        if (!$state) {
            throw new RuntimeException('Round has not started yet.');
        }
        $membership = require_membership($pdo, $gameId, (int)$player['id']);
        require_host($game, $membership);
        if ($state['phase'] !== 'round_end') {
            throw new RuntimeException('The current round is still in progress.');
        }

        $needed = $game['variant'] === '3p' ? 3 : 4;

        // Everyone still active is eligible for a seat next round. Seated
        // players from the just-finished round get priority to keep their
        // spot, then spectators fill any remaining seats, in join order.
        $stmt = $pdo->prepare("SELECT * FROM game_players WHERE game_id = ? AND status = 'active' ORDER BY (seat IS NULL) ASC, joined_at ASC");
        $stmt->execute([$gameId]);
        $activePlayers = $stmt->fetchAll();

        if (count($activePlayers) < $needed) {
            $pdo->prepare("UPDATE game_players SET role='spectator', seat=NULL, team=NULL WHERE game_id=? AND status='active'")->execute([$gameId]);
            set_game_status($pdo, $gameId, 'lobby');
            post_chat($pdo, $gameId, null, 'System', "Not enough players to continue ($needed needed) — back to the lobby.", true);
            $pdo->commit();
            json_response(['ok' => true, 'returned_to_lobby' => true]);
            return;
        }

        // Match-end check (marks mode) before dealing another round.
        $settings = $game['settings'];
        if ($settings['scoring_mode'] === 'marks' && (int)$settings['target_marks'] > 0 && $game['variant'] === '4p') {
            $target = (int)$settings['target_marks'];
            $scores = [];
            foreach ($activePlayers as $p) {
                if ($p['seat'] !== null) {
                    $scores[$p['seat'] % 2] = (int)$p['score'];
                }
            }
            $winnerTeam = null;
            if (($scores[0] ?? 0) >= $target) $winnerTeam = 0;
            elseif (($scores[1] ?? 0) >= $target) $winnerTeam = 1;
            elseif (($scores[0] ?? 0) <= -$target) $winnerTeam = 1;
            elseif (($scores[1] ?? 0) <= -$target) $winnerTeam = 0;

            if ($winnerTeam !== null) {
                set_game_status($pdo, $gameId, 'finished');
                $pdo->prepare("UPDATE game_state SET phase='finished' WHERE game_id=?")->execute([$gameId]);
                post_chat($pdo, $gameId, null, 'System', 'Team ' . ($winnerTeam + 1) . ' wins the match at ' . $target . ' marks!', true);
                $pdo->commit();
                json_response(['ok' => true, 'finished' => true]);
                return;
            }
        }
        if ((int)$settings['round_limit'] > 0 && $state['round_no'] >= (int)$settings['round_limit']) {
            set_game_status($pdo, $gameId, 'finished');
            $pdo->prepare("UPDATE game_state SET phase='finished' WHERE game_id=?")->execute([$gameId]);
            post_chat($pdo, $gameId, null, 'System', 'Round limit reached — match complete!', true);
            $pdo->commit();
            json_response(['ok' => true, 'finished' => true]);
            return;
        }

        $seatPlayers = [];
        if ($game['variant'] === '4p') {
            // 4p partnerships are fixed for the whole match (players 1&3 vs
            // 2&4) — only the dealer rotates each round. Keep everyone who's
            // still seated exactly where they are; only a seat vacated by a
            // player who left gets handed to the next waiting spectator.
            $stillSeated = array_values(array_filter($activePlayers, fn($p) => $p['seat'] !== null));
            $waiting = array_values(array_filter($activePlayers, fn($p) => $p['seat'] === null));
            foreach ($stillSeated as $p) {
                $seatPlayers[(int)$p['seat']] = (int)$p['player_id'];
            }
            foreach (range(0, $needed - 1) as $s) {
                if (isset($seatPlayers[$s])) {
                    continue;
                }
                $p = array_shift($waiting);
                if ($p === null) {
                    break; // shouldn't happen — the count($activePlayers) >= $needed check above guarantees enough players
                }
                $team = $s % 2;
                $pdo->prepare('UPDATE game_players SET seat=?, team=?, role=\'player\' WHERE id=?')
                    ->execute([$s, $team, $p['id']]);
                $seatPlayers[$s] = (int)$p['player_id'];
            }
            foreach ($waiting as $p) {
                $pdo->prepare('UPDATE game_players SET seat=NULL, team=NULL, role=\'spectator\' WHERE id=?')->execute([$p['id']]);
            }
        } else {
            // 3p has no partnerships to preserve — reshuffle seating each
            // round, as documented.
            shuffle($activePlayers); // only the first $needed of this shuffled order get a seat
            $seat = 0;
            foreach ($activePlayers as $p) {
                if ($seat < $needed) {
                    $pdo->prepare('UPDATE game_players SET seat=?, team=NULL, role=\'player\' WHERE id=?')
                        ->execute([$seat, $p['id']]);
                    $seatPlayers[$seat] = (int)$p['player_id'];
                    $seat++;
                } else {
                    $pdo->prepare('UPDATE game_players SET seat=NULL, team=NULL, role=\'spectator\' WHERE id=?')->execute([$p['id']]);
                }
            }
        }
        ksort($seatPlayers);

        $newState = GameEngine::startRound($game, $state, $seatPlayers);
        save_state($pdo, $gameId, $newState);
        set_game_status($pdo, $gameId, 'bidding');
        $dealMsg = $game['variant'] === '4p' ? 'Round ' . $newState['round_no'] . ' dealt.' : 'Round ' . $newState['round_no'] . ' dealt. Seats reshuffled.';
        post_chat($pdo, $gameId, null, 'System', $dealMsg, true);

        $pdo->commit();
        json_response(['ok' => true]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
});
