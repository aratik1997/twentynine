<?php
declare(strict_types=1);
/** Second smoke pass: leave-mid-round autoplay, and host cancel_round. */

const BASE = 'http://localhost/twentynine/api/';

function call(string $jar, string $path, array $body = null, string $method = 'POST'): array
{
    $ch = curl_init();
    $url = BASE . $path;
    if ($method === 'GET' && $body) { $url .= '?' . http_build_query($body); $body = null; }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($res, true);
    if (!is_array($data)) throw new RuntimeException("Bad JSON from $path (HTTP $code): $res");
    if (!($data['ok'] ?? false)) throw new RuntimeException("API error from $path: " . ($data['error'] ?? 'unknown') . " (HTTP $code)");
    return $data;
}
function jar(int $i): string { $p = sys_get_temp_dir() . "/tn_smoke2_$i.jar"; if (file_exists($p)) unlink($p); return $p; }
function myState(array $jars, int $i, int $gameId): array { return call($jars[$i], 'state.php', ['game_id' => $gameId], 'GET'); }

$jars = [];
for ($i = 0; $i < 4; $i++) { $jars[$i] = jar($i); call($jars[$i], 'set_nickname.php', ['nickname' => "Runner$i"]); }

$created = call($jars[0], 'create_game.php', ['variant' => '4p', 'name' => 'Leave Test']);
$gameId = $created['game_id']; $code = $created['code'];
for ($i = 1; $i < 4; $i++) {
    call($jars[$i], 'join_game.php', ['code' => $code]);
    call($jars[$i], 'take_seat.php', ['game_id' => $gameId, 'seat' => $i]);
}
call($jars[0], 'start_game.php', ['game_id' => $gameId]);
echo "✓ 4p game started ($code)\n";

$seatToJar = [];
foreach (range(0, 3) as $i) { $s = myState($jars, $i, $gameId); $seatToJar[$s['me']['seat']] = $i; }
ksort($seatToJar);

// --- Test cancel_round mid-bidding ---
$st = myState($jars, $seatToJar[0], $gameId);
$roundBefore = $st['state']['round_no'];
$turnSeat = $st['state']['turn_seat'];
call($jars[$seatToJar[$turnSeat]], 'pass_bid.php', ['game_id' => $gameId]);
call($jars[0], 'cancel_round.php', ['game_id' => $gameId]);
$st2 = myState($jars, $seatToJar[0], $gameId);
if ($st2['state']['round_no'] !== $roundBefore) throw new RuntimeException('cancel_round should keep the same round_no');
if ($st2['state']['bid_history'] !== []) throw new RuntimeException('cancel_round should reset bid history');
echo "✓ cancel_round redealt the same round (round_no=$roundBefore) and reset bidding\n";

// --- Test leave mid-round: whoever's turn it is leaves, table must keep moving ---
$guard = 0;
while (true) {
    if (++$guard > 20) throw new RuntimeException('Bidding did not converge');
    $st = myState($jars, $seatToJar[0], $gameId);
    if ($st['state']['phase'] !== 'bidding') break;
    $turnSeat = $st['state']['turn_seat'];
    if ($st['state']['highest_bid'] === null) {
        call($jars[$seatToJar[$turnSeat]], 'bid.php', ['game_id' => $gameId, 'value' => 17]);
    } else {
        call($jars[$seatToJar[$turnSeat]], 'pass_bid.php', ['game_id' => $gameId]);
    }
}
$st = myState($jars, $seatToJar[0], $gameId);
$bidderSeat = $st['state']['bidder_seat'];
call($jars[$seatToJar[$bidderSeat]], 'select_trump.php', ['game_id' => $gameId, 'suit' => 'H']);
call($jars[$seatToJar[$bidderSeat]], 'proceed_to_play.php', ['game_id' => $gameId]);
echo "✓ trump selected, now in play phase\n";

// Whoever's turn it is now, have them LEAVE instead of playing.
$st = myState($jars, $seatToJar[0], $gameId);
$leavingSeat = $st['state']['turn_seat'];
$leavingJar = $seatToJar[$leavingSeat];
call($jars[$leavingJar], 'leave_game.php', ['game_id' => $gameId]);
echo "✓ seat $leavingSeat left mid-trick\n";

// Any other active player's poll should trigger autoplay resolution and
// move the turn to someone who is NOT the departed seat.
$otherIdx = $seatToJar[($leavingSeat + 1) % 4];
$after = myState($jars, $otherIdx, $gameId);
if ($after['state']['turn_seat'] === $leavingSeat) {
    throw new RuntimeException('Turn is still stuck on the departed seat — autoplay did not resolve it');
}
if ($after['state']['phase'] === 'round_end') {
    echo "✓ round auto-completed after the departure (turn no longer stuck)\n";
} else {
    echo "✓ autoplay moved the turn off the departed seat $leavingSeat to seat {$after['state']['turn_seat']}\n";
}

echo "\nALL SECOND-PASS SMOKE TESTS PASSED\n";
