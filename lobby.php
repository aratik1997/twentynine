<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/helpers.php';

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
$game = $code !== '' ? game_by_code($code) : null;
if (!$game) {
    header('Location: index.php');
    exit;
}
$__pageTitle = 'Lobby — ' . htmlspecialchars($game['name']) . ' (' . $code . ')';
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
    <div class="room-code panel" style="padding:.5em 1em;">
      <span class="label">TABLE CODE</span>
      <span id="roomCode"><?= htmlspecialchars($code) ?></span>
    </div>
    <div class="row">
      <button class="btn secondary small" id="rulesBtn">📖 Rules</button>
      <button class="btn secondary small" id="soundToggleBtn">🔊 Sound</button>
      <button class="btn danger small" id="leaveBtn">Leave</button>
    </div>
  </div>

  <div class="lobby-layout">
    <div>
      <div class="panel mb-1">
        <div class="row between">
          <h2 id="tableName">Table</h2>
          <span class="chip" id="variantChip">—</span>
        </div>
        <div class="bot-level-row">
          <label class="muted" style="font-size:.85rem">Bot level
            <select id="botDifficulty" class="btn small secondary">
              <option value="easy">Easy</option>
              <option value="medium" selected>Medium</option>
              <option value="hard">Hard</option>
            </select>
          </label>
        </div>
        <div class="seat-grid" id="seatGrid"></div>
        <div class="spectator-list" id="spectatorList"></div>
        <div class="row mt-1 between">
          <span class="muted" id="seatCountLabel"></span>
          <button class="btn block" id="startBtn" style="max-width:220px" disabled>Start Game</button>
        </div>
      </div>

      <div class="panel" id="settingsPanel">
        <h2>House Rules &amp; Settings</h2>
        <div class="settings-list" id="settingsList"></div>
        <p class="muted" id="settingsLockedNote" style="font-size:.8rem"></p>
      </div>
    </div>

    <div class="panel chat-panel">
      <h2 style="margin-bottom:.5em">Table Chat</h2>
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
<script src="<?= asset_url('assets/js/lobby.js') ?>"></script>
</body>
</html>
