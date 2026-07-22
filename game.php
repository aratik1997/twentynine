<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/helpers.php';

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
$game = $code !== '' ? game_by_code($code) : null;
if (!$game) {
    header('Location: index.php');
    exit;
}
$__pageTitle = '29 — ' . htmlspecialchars($game['name']) . ' (' . $code . ')';
?>
<!doctype html>
<html lang="en">
<head>
<?php include __DIR__ . '/includes/head_meta.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js" integrity="sha512-7eHRwcbYkK4d9g/6tD/mhkf++eoTHwpNM9woBxtPUBWm67zeAfFC+HrdoE2GanKeocly/VxeLvIqwvCdk7qScg==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
</head>
<body>
<div class="game-shell">
  <div class="table-area">
    <div class="turn-banner" id="turnBanner">—</div>
    <div class="table-oval" id="tableOval">
      <div class="trick-area" id="trickArea"></div>
      <div class="center-info" id="centerInfo"></div>
    </div>
    <div class="hand-tray" id="handTray"></div>
  </div>

  <div class="side-panel">
    <div class="row between">
      <a href="index.php" class="brand" style="font-size:1rem"><img src="assets/img/logo.svg" alt="" style="width:28px;height:28px"> 29</a>
      <div class="row" style="gap:.3em">
        <button class="btn secondary icon-only small" id="rulesBtn" title="Rules">📖</button>
        <button class="btn secondary icon-only small" id="soundToggleBtn" title="Sound">🔊</button>
        <button class="btn danger icon-only small" id="leaveBtn" title="Leave">🚪</button>
      </div>
    </div>

    <div class="status-bar" id="statusBar">Loading table…</div>

    <div class="row wrap" id="hostControls" style="display:none">
      <button class="btn small" id="nextRoundBtn" style="display:none">▶ Deal Next Round</button>
    </div>

    <div class="side-tabs">
      <button class="active" data-tab="action">Table</button>
      <button data-tab="score">Score</button>
      <button data-tab="chat">Chat</button>
    </div>

    <div class="side-tab-panel active" data-tab="action" id="actionPanel"></div>

    <div class="side-tab-panel" data-tab="score">
      <div style="overflow-x:auto">
        <table class="scoreboard-table" id="scoreTable"><thead></thead><tbody></tbody></table>
      </div>
      <div class="bid-history-list mt-1" id="bidHistoryList"></div>
    </div>

    <div class="side-tab-panel" data-tab="chat">
      <div class="chat-log" id="chatLog"></div>
      <div class="chat-input-row">
        <input type="text" id="chatInput" maxlength="300" placeholder="Say something…">
        <button class="btn small" id="chatSendBtn">Send</button>
      </div>
    </div>
  </div>
</div>

<div id="rulesModalRoot"></div>

<script src="<?= asset_url('assets/js/api.js') ?>"></script>
<script src="<?= asset_url('assets/js/sound.js') ?>"></script>
<script>window.GAME_ID = <?= (int)$game['id'] ?>; window.GAME_CODE = <?= json_encode($code) ?>;</script>
<script src="<?= asset_url('assets/js/game.js') ?>"></script>
</body>
</html>
