<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/helpers.php';
$__pageTitle = '29 — The Card Game';
?>
<!doctype html>
<html lang="en">
<head>
<?php include __DIR__ . '/includes/head_meta.php'; ?>
</head>
<body>
<div class="landing">
  <img src="assets/img/logo.svg" alt="29 Card Game" class="logo-hero">
  <h1>29 <span class="gold-text">— The Card Game</span></h1>
  <p class="tagline">The classic South Asian trick-taking card game — Jack &amp; Nine reign supreme. Play the traditional 4-player partnership game, or the configurable 3-player variant, online with friends in real time.</p>

  <div class="nickname-bar panel">
    <label for="nickname" style="margin:0">Your name</label>
    <input type="text" id="nickname" maxlength="20" placeholder="Enter your name">
    <button class="btn small" id="saveName">Save</button>
    <span class="muted" id="nameStatus" style="font-size:.85rem"></span>
  </div>

  <div class="landing-grid">
    <div class="panel">
      <h2>Create a Table</h2>
      <div class="field">
        <label>Table name</label>
        <input type="text" id="tableName" maxlength="40" placeholder="e.g. Friday Night 29">
      </div>
      <div class="field">
        <label>Players</label>
        <div class="variant-pick">
          <label><input type="radio" name="variant" value="4p" checked><span>4 Players<br><small class="muted">Traditional partnerships</small></span></label>
          <label><input type="radio" name="variant" value="3p"><span>3 Players<br><small class="muted">Configurable variant</small></span></label>
        </div>
      </div>
      <button class="btn block" id="createBtn">Create Table</button>
    </div>

    <div class="panel">
      <h2>Join a Table</h2>
      <div class="field">
        <label>Table code</label>
        <input type="text" id="joinCode" maxlength="6" placeholder="e.g. AB12CD" style="text-transform:uppercase; letter-spacing:.15em; font-size:1.1rem">
      </div>
      <button class="btn secondary block" id="joinBtn">Join Table</button>
      <p class="muted mt-1" style="font-size:.82rem">Joining mid-game? You'll spectate until the current round finishes, then take the next open seat.</p>
    </div>
  </div>

  <div class="footer-links">
    <button type="button" class="btn secondary small" onclick="location.href='rules.php'">📖 Game Rules</button>
  </div>
</div>

<script src="<?= asset_url('assets/js/api.js') ?>"></script>
<script>
(async function () {
  const nameInput = document.getElementById('nickname');
  const nameStatus = document.getElementById('nameStatus');

  async function refreshWhoami() {
    try {
      const { player } = await Api.get('whoami.php');
      if (player) { nameInput.value = player.nickname; nameStatus.textContent = 'Saved ✓'; }
    } catch (e) { /* first visit, no identity yet */ }
  }
  await refreshWhoami();

  document.getElementById('saveName').addEventListener('click', async () => {
    const nickname = nameInput.value.trim();
    if (!nickname) { toast('Enter a name first.', { warn: true }); return; }
    try {
      await Api.post('set_nickname.php', { nickname });
      nameStatus.textContent = 'Saved ✓';
      toast('Name saved.');
    } catch (e) { toast(e.message, { warn: true }); }
  });

  async function ensureIdentity() {
    const nickname = nameInput.value.trim();
    if (!nickname) throw new Error('Enter a name first.');
    await Api.post('set_nickname.php', { nickname });
  }

  document.getElementById('createBtn').addEventListener('click', async () => {
    try {
      await ensureIdentity();
      const variant = document.querySelector('input[name=variant]:checked').value;
      const name = document.getElementById('tableName').value.trim();
      const { code } = await Api.post('create_game.php', { variant, name });
      location.href = 'lobby.php?code=' + encodeURIComponent(code);
    } catch (e) { toast(e.message, { warn: true }); }
  });

  document.getElementById('joinBtn').addEventListener('click', async () => {
    try {
      await ensureIdentity();
      const code = document.getElementById('joinCode').value.trim().toUpperCase();
      if (!code) { toast('Enter a table code.', { warn: true }); return; }
      await Api.post('join_game.php', { code });
      location.href = 'lobby.php?code=' + encodeURIComponent(code);
    } catch (e) { toast(e.message, { warn: true }); }
  });

  document.getElementById('joinCode').addEventListener('keyup', (e) => {
    e.target.value = e.target.value.toUpperCase();
    if (e.key === 'Enter') document.getElementById('joinBtn').click();
  });
})();
</script>
</body>
</html>
