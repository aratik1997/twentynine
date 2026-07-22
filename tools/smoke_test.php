<?php
declare(strict_types=1);
/**
 * End-to-end smoke test against the live API over HTTP, using cookie
 * jars to simulate 4 separate browser sessions. Not a unit test suite —
 * just enough to prove the full loop (create -> join -> start -> bid ->
 * trump -> play 8 tricks -> round_end -> next_round) works without
 * throwing. Run with the app already being served by Apache/XAMPP.
 *   php tools/smoke_test.php
 */

const BASE = 'http://localhost/twentynine/api/';

function call(string $jar, string $path, array $body = null, string $method = 'POST'): array
{
    $ch = curl_init();
    $url = BASE . $path;
    if ($method === 'GET' && $body) {
        $url .= '?' . http_build_query($body);
        $body = null;
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $res = curl_exec($ch);
    if ($res === false) {
        throw new RuntimeException('curl error: ' . curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($res, true);
    if (!is_array($data)) {
        throw new RuntimeException("Bad JSON from $path (HTTP $code): $res");
    }
    if (!($data['ok'] ?? false)) {
        throw new RuntimeException("API error from $path: " . ($data['error'] ?? 'unknown') . " (HTTP $code)");
    }
    return $data;
}

function jar(int $i): string
{
    $p = sys_get_temp_dir() . "/tn_smoke_$i.jar";
    if (file_exists($p)) unlink($p);
    return $p;
}

$jars = [];
for ($i = 0; $i < 4; $i++) {
    $jars[$i] = jar($i);
    call($jars[$i], 'set_nickname.php', ['nickname' => "Bot$i"]);
}
echo "✓ 4 identities created\n";

$created = call($jars[0], 'create_game.php', ['variant' => '4p', 'name' => 'Smoke Test Table']);
$code = $created['code'];
$gameId = $created['game_id'];
echo "✓ game created: $code (id=$gameId)\n";

for ($i = 1; $i < 4; $i++) {
    call($jars[$i], 'join_game.php', ['code' => $code]);
    call($jars[$i], 'take_seat.php', ['game_id' => $gameId, 'seat' => $i]);
}
echo "✓ 3 more players joined and seated\n";

call($jars[0], 'start_game.php', ['game_id' => $gameId]);
echo "✓ game started\n";

function seatOf(array $state, int $playerIndexInJars, array $jars): int
{
    // not directly used; we resolve turn via state polling per-jar instead.
    return -1;
}

function myState(array $jars, int $i, int $gameId): array
{
    return call($jars[$i], 'state.php', ['game_id' => $gameId], 'GET');
}

// Figure out which jar sits in which seat.
$seatToJar = [];
foreach (range(0, 3) as $i) {
    $s = myState($jars, $i, $gameId);
    $seatToJar[$s['me']['seat']] = $i;
}
ksort($seatToJar);
echo '✓ seat map: ' . json_encode($seatToJar) . "\n";

// --- Bidding: everyone but seat 0 passes, seat 0 bids 16 then wins. ---
$guard = 0;
while (true) {
    if (++$guard > 20) throw new RuntimeException('Bidding did not converge');
    $anyState = myState($jars, $seatToJar[0], $gameId);
    if ($anyState['state']['phase'] !== 'bidding') break;
    $turnSeat = $anyState['state']['turn_seat'];
    $jarIdx = $seatToJar[$turnSeat];
    if ($turnSeat === 0 && $anyState['state']['highest_bid'] === null) {
        call($jars[$jarIdx], 'bid.php', ['game_id' => $gameId, 'value' => 16]);
        echo "  seat0 bids 16\n";
    } else {
        call($jars[$jarIdx], 'pass_bid.php', ['game_id' => $gameId]);
        echo "  seat$turnSeat passes\n";
    }
}
echo "✓ bidding resolved\n";

$st = myState($jars, $seatToJar[0], $gameId);
if ($st['state']['phase'] !== 'trump_select') throw new RuntimeException('Expected trump_select, got ' . $st['state']['phase']);
$bidderSeat = $st['state']['bidder_seat'];
call($jars[$seatToJar[$bidderSeat]], 'select_trump.php', ['game_id' => $gameId, 'suit' => 'S']);
echo "✓ trump selected by seat $bidderSeat\n";

// Trump selection no longer auto-deals the second batch — that's a
// separate, real pause window (Double/Redouble) closed by the bidder.
call($jars[$seatToJar[$bidderSeat]], 'proceed_to_play.php', ['game_id' => $gameId]);
echo "✓ bidder proceeded to play (second batch dealt)\n";

// --- Play all 8 tricks, always playing legal_cards[0]. ---
$tricksPlayed = 0;
$guard = 0;
while (true) {
    if (++$guard > 200) throw new RuntimeException('Play loop did not converge');
    $st = myState($jars, $seatToJar[0], $gameId);
    if ($st['state']['phase'] === 'round_end') break;
    if ($st['state']['phase'] !== 'playing') throw new RuntimeException('Unexpected phase: ' . $st['state']['phase']);
    $turnSeat = $st['state']['turn_seat'];
    $jarIdx = $seatToJar[$turnSeat];
    $mySt = myState($jars, $jarIdx, $gameId);
    $legal = $mySt['legal_cards'];
    if (!$legal) throw new RuntimeException("No legal cards for seat $turnSeat");
    call($jars[$jarIdx], 'play_card.php', ['game_id' => $gameId, 'card' => $legal[0]]);
    $tricksPlayed++;
}
echo "✓ round played to completion ($tricksPlayed cards played)\n";

$final = myState($jars, $seatToJar[0], $gameId);
echo '✓ round_end result: ' . json_encode($final['state']['last_result']) . "\n";

// --- Next round (host = jar 0). ---
call($jars[0], 'next_round.php', ['game_id' => $gameId]);
$after = myState($jars, $seatToJar[0] ?? 0, $gameId);
echo '✓ next round dealt: round_no=' . $after['state']['round_no'] . ', phase=' . $after['state']['phase'] . "\n";

// --- Kick flow sanity check on a fresh lobby-phase game. ---
$created2 = call($jars[0], 'create_game.php', ['variant' => '3p', 'name' => 'Kick Test']);
call($jars[1], 'join_game.php', ['code' => $created2['code']]);
$s1 = myState($jars, 1, $created2['game_id']);
call($jars[0], 'kick_player.php', ['game_id' => $created2['game_id'], 'target_player_id' => $s1['me']['player_id']]);
$afterKick = myState($jars, 1, $created2['game_id']);
if ($afterKick['me']['status'] !== 'kicked') throw new RuntimeException('Kick did not register');
echo "✓ kick flow works (3p game)\n";

echo "\nALL SMOKE TESTS PASSED\n";
