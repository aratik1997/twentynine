<?php
declare(strict_types=1);
$embed = isset($_GET['embed']);
$__pageTitle = 'How to Play 29';

ob_start();
?>
<div class="rule-section">
  <h2 class="gold-text" style="margin-top:0">29 (Twenty-Nine) — How to Play</h2>
  <p>29 is a trick-taking card game popular across Bangladesh, India and Nepal, traditionally played by <b>4 players in two fixed partnerships</b>. This app also offers a <b>3-player variant</b>, which — since no single official 3-player rule set exists — is implemented as a clearly-labelled, configurable house variant (see below).</p>
</div>

<div class="rule-section">
  <h3>The Deck</h3>
  <p>32 cards: <b>7, 8, 9, 10, J, Q, K, A</b> of all four suits. Card ranking, highest to lowest:</p>
  <table class="rule-table">
    <tr><th>J</th><th>9</th><th>A</th><th>10</th><th>K</th><th>Q</th><th>8</th><th>7</th></tr>
    <tr><td>Highest</td><td colspan="6"></td><td>Lowest</td></tr>
  </table>
  <p>Card point values (28 points in the deck total):</p>
  <table class="rule-table">
    <tr><th>J</th><td>3</td><th>9</th><td>2</td><th>A</th><td>1</td><th>10</th><td>1</td><th>K / Q / 8 / 7</th><td>0</td></tr>
  </table>
</div>

<div class="rule-section">
  <h3>4-Player Game (Traditional)</h3>
  <p>Players 1 &amp; 3 partner against players 2 &amp; 4; partners sit opposite each other. The dealer rotates clockwise each round.</p>
  <ol>
    <li><b>Deal:</b> 4 cards to each player. A bidding round follows.</li>
    <li><b>Bidding:</b> starting left of the dealer, each player bids higher than the current highest bid or passes, between the table's minimum (default 16) and maximum (28). Once all but one player has passed, the highest bidder wins the auction. If everyone passes without a bid, the dealer is forced to bid the minimum.</li>
    <li><b>Trump selection:</b> the winning bidder secretly chooses a trump suit. It stays hidden from everyone else.</li>
    <li><b>Second deal:</b> each player receives 4 more cards (8 total).</li>
    <li><b>Play:</b> the player left of the dealer leads the first trick. Everyone must follow the suit led if able. A player unable to follow suit may play anything, including a trump card — the moment any trump-suit card is played, trump is <b>revealed</b> from then on, and trump cards beat all other suits for the rest of the round.</li>
    <li><b>Scoring:</b> after all 8 tricks, if the bidding side collected at least as many card points as they bid, their bid <b>succeeds</b>; otherwise it <b>fails</b>.</li>
  </ol>
</div>

<div class="rule-section">
  <h3>Scoring Modes</h3>
  <p><b>Traditional marks:</b> the bidding side gains 1 mark on success or loses 1 mark on failure (only the bidding side's mark total moves each round). With the default match target of 6 marks, the first side to +6 wins the match; reaching −6 loses it for the opponent.</p>
  <p><b>Numeric points:</b> the bidding side gains the bid value on success, or loses it on failure. A configurable round limit can end the match instead of a marks target.</p>
  <p>The scoreboard's sort order (highest-first or lowest-first) is configurable per table in Settings.</p>
</div>

<div class="rule-section">
  <h3>House Rules (configurable per table)</h3>
  <ul>
    <li><b>Double:</b> either opponent of the bidder may double the stakes before the first trick is complete.</li>
    <li><b>Re-Double:</b> after a double, the bidder's side may redouble (before the second trick is complete), doubling the stakes again.</li>
    <li><b>Auto Trump:</b> skips manual trump selection — the engine picks the bidder's strongest suit automatically.</li>
    <li><b>Single Hand:</b> the bidder may declare they'll play the hand alone against everyone (a bonus/risk house call).</li>
    <li><b>29th-point bonus:</b> optionally award the winner of the last trick one bonus point.</li>
  </ul>
</div>

<div class="rule-section">
  <h3>3-Player Variant (Configurable — No Official Standard)</h3>
  <p>There's no single traditional rule set for 3-player 29, so this app implements a clearly-labelled house variant that keeps 29's core mechanics intact:</p>
  <ol>
    <li>8 cards are removed from the 32-card deck before dealing (default: randomly), leaving 24 cards.</li>
    <li>Each of the 3 players is dealt 8 cards (all at once by default — a "deal 4, bid, deal 4 more" style can be toggled in settings).</li>
    <li>Bidding, trump selection and reveal, and follow-suit play work exactly as in the 4-player game — just with no partnerships.</li>
    <li>The highest bidder must personally collect at least their bid in card points to succeed.</li>
    <li>The other two players are ranked by the card points they personally collected.</li>
    <li>Scores accumulate across rounds; seats reshuffle every round.</li>
  </ol>
</div>

<div class="rule-section">
  <h3>Table Features</h3>
  <ul>
    <li>The host can kick a player from the lobby or the game table at any time.</li>
    <li>Any player can leave via the Leave button — they're skipped starting the following round.</li>
    <li>Players joining mid-game spectate until the current round finishes, then take the next open seat.</li>
    <li>The dealer rotates clockwise every round. In the 4-player game, partnerships are fixed for the whole match — a departed player's seat is handed to the next waiting spectator, but everyone else keeps their seat and partner. In the 3-player variant (no partnerships), seating reshuffles every round.</li>
    <li>The host can cancel and redeal the current round at any time with "New Round."</li>
  </ul>
  <p class="muted" style="font-size:.8rem">Rules researched from <a href="https://www.pagat.com/jass/29.html" target="_blank" rel="noopener">pagat.com's Twenty-Nine reference</a>, adapted for this implementation's online/automated play (in particular, the trump-reveal trigger and double/redouble timing windows are simplified for automatic enforcement).</p>
</div>
<?php
$content = ob_get_clean();

if ($embed) {
    echo $content;
    return;
}
?>
<!doctype html>
<html lang="en">
<head>
<?php include __DIR__ . '/includes/head_meta.php'; ?>
</head>
<body>
<div class="lobby-wrap">
  <div class="lobby-header">
    <a href="index.php" class="brand"><img src="assets/img/logo.svg" alt=""> 29</a>
    <a href="index.php" class="btn secondary small">← Back</a>
  </div>
  <div class="panel"><?= $content ?></div>
</div>
</body>
</html>
