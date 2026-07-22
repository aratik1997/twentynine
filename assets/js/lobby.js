(function () {
  const gameId = window.GAME_ID;
  let lastChatId = 0;
  let redirected = false;
  let soundOn = localStorage.getItem('tn_sound') !== 'off';
  Sound.setEnabled(soundOn);
  updateSoundBtn();

  document.getElementById('soundToggleBtn').addEventListener('click', () => {
    soundOn = !soundOn;
    localStorage.setItem('tn_sound', soundOn ? 'on' : 'off');
    Sound.setEnabled(soundOn);
    Sound.unlock();
    updateSoundBtn();
  });
  function updateSoundBtn() {
    document.getElementById('soundToggleBtn').textContent = soundOn ? '🔊 Sound' : '🔇 Muted';
  }

  document.getElementById('rulesBtn').addEventListener('click', () => window.open('rules.php', '_blank'));

  document.getElementById('leaveBtn').addEventListener('click', async () => {
    try { await Api.post('leave_game.php', { game_id: gameId }); } catch (e) {}
    location.href = 'index.php';
  });

  document.getElementById('chatSendBtn').addEventListener('click', sendChat);
  document.getElementById('chatInput').addEventListener('keyup', (e) => { if (e.key === 'Enter') sendChat(); });
  async function sendChat() {
    const input = document.getElementById('chatInput');
    const msg = input.value.trim();
    if (!msg) return;
    input.value = '';
    try { await Api.post('chat_send.php', { game_id: gameId, message: msg }); } catch (e) { toast(e.message, { warn: true }); }
  }

  document.getElementById('startBtn').addEventListener('click', async () => {
    try { await Api.post('start_game.php', { game_id: gameId }); } catch (e) { toast(e.message, { warn: true }); }
  });

  const SETTINGS_META = (variant) => [
    { key: 'min_bid', label: 'Minimum bid', type: 'number', min: 10, max: 27 },
    { key: 'max_bid', label: 'Maximum bid', type: 'number', min: 11, max: 28 },
    { key: 'scoring_mode', label: 'Scoring mode', type: 'select', options: [['marks', 'Traditional marks'], ['numeric', 'Numeric points']] },
    { key: 'scoreboard_order', label: 'Scoreboard sort', type: 'select', options: [['desc', 'Highest score wins'], ['asc', 'Lowest score wins']] },
    ...(variant === '4p' ? [{ key: 'target_marks', label: 'Marks to win match (0 = no limit)', type: 'number', min: 0, max: 20 }] : []),
    { key: 'round_limit', label: 'Round limit (0 = unlimited)', type: 'number', min: 0, max: 200 },
    { key: 'allow_double', label: 'Allow Double', type: 'toggle', hint: 'Defending side can double the stakes before the first trick.' },
    { key: 'allow_redouble', label: 'Allow Re-Double', type: 'toggle', hint: 'Bidding side can redouble after a double.' },
    { key: 'auto_trump', label: 'Auto Trump', type: 'toggle', hint: "Skip manual trump choice — engine picks the bidder's strongest suit." },
    { key: 'single_hand', label: 'Single Hand', type: 'toggle', hint: 'Let the bidder declare Single Hand as a house-rule option.' },
    { key: 'last_trick_bonus', label: '29th point (last-trick bonus)', type: 'toggle', hint: 'Winner of the 8th trick gets +1 bonus point.' },
    { key: 'trick_reveal_seconds', label: 'Trick reveal pause (seconds)', type: 'number', min: 3, max: 10, hint: 'How long a finished trick stays on the table before being swept away.' },
    ...(variant === '3p' ? [{ key: 'deal_style', label: '3-player deal style', type: 'select', options: [['full', 'Deal all 8 upfront (default)'], ['phased', 'Deal 4, bid, then deal 4 more']] }] : []),
  ];

  let cachedSettings = null;
  let settingsRenderedFor = null;

  function renderSettings(settings, variant, isHost, locked) {
    const list = document.getElementById('settingsList');
    const key = JSON.stringify(settings) + variant + isHost + locked;
    if (settingsRenderedFor === key) return;
    settingsRenderedFor = key;
    list.innerHTML = '';
    SETTINGS_META(variant).forEach((meta) => {
      const row = document.createElement('div');
      row.className = 'toggle-row';
      const left = document.createElement('div');
      left.innerHTML = `<div>${meta.label}</div>` + (meta.hint ? `<div class="hint">${meta.hint}</div>` : '');
      row.appendChild(left);

      let control;
      if (meta.type === 'toggle') {
        control = document.createElement('label');
        control.className = 'switch';
        control.innerHTML = `<input type="checkbox" ${settings[meta.key] ? 'checked' : ''} ${(!isHost || locked) ? 'disabled' : ''}><span class="slider"></span>`;
        control.querySelector('input').addEventListener('change', (e) => patchSetting(meta.key, e.target.checked));
      } else if (meta.type === 'select') {
        control = document.createElement('select');
        control.disabled = !isHost || locked;
        meta.options.forEach(([val, label]) => {
          const opt = document.createElement('option');
          opt.value = val; opt.textContent = label;
          if (settings[meta.key] === val) opt.selected = true;
          control.appendChild(opt);
        });
        control.addEventListener('change', (e) => patchSetting(meta.key, e.target.value));
      } else {
        control = document.createElement('input');
        control.type = 'number';
        control.min = meta.min; control.max = meta.max;
        control.style.width = '80px';
        control.value = settings[meta.key];
        control.disabled = !isHost || locked;
        control.addEventListener('change', (e) => patchSetting(meta.key, parseInt(e.target.value, 10)));
      }
      row.appendChild(control);
      list.appendChild(row);
    });
    document.getElementById('settingsLockedNote').textContent = locked
      ? 'Settings lock once the game starts.'
      : (isHost ? '' : 'Only the host can change settings.');
  }

  let patchTimer = null;
  let pendingPatch = {};
  function patchSetting(key, value) {
    pendingPatch[key] = value;
    cachedSettings[key] = value;
    clearTimeout(patchTimer);
    patchTimer = setTimeout(async () => {
      const patch = pendingPatch; pendingPatch = {};
      try { await Api.post('update_settings.php', { game_id: gameId, settings: patch }); }
      catch (e) { toast(e.message, { warn: true }); }
    }, 350);
  }

  const SEAT_COLORS_FALLBACK = '#4a7c59';

  function renderSeats(data) {
    const { game, players, me } = data;
    document.getElementById('tableName').textContent = game.name;
    document.getElementById('variantChip').textContent = game.variant === '3p' ? '3 Players' : '4 Players (Partnerships)';
    document.getElementById('roomCode').textContent = game.code;

    const needed = game.needed_seats;
    const seatGrid = document.getElementById('seatGrid');
    seatGrid.innerHTML = '';
    const bySeat = {};
    players.forEach((p) => { if (p.seat !== null && p.role === 'player') bySeat[p.seat] = p; });

    const canPickSeat = game.status === 'lobby';
    for (let s = 0; s < needed; s++) {
      const p = bySeat[s];
      const div = document.createElement('div');
      div.className = 'seat-card' + (p ? '' : ' empty');
      if (p) {
        const canKick = me.is_host && p.player_id !== me.player_id;
        const isMe = p.player_id === me.player_id;
        div.innerHTML = `
          <div class="seat-avatar" style="background:${p.avatar_color || SEAT_COLORS_FALLBACK}">${p.is_bot ? '🤖' : p.nickname.slice(0,1).toUpperCase()}</div>
          <div class="seat-meta">
            <div class="seat-name">${escapeHtml(p.nickname)}${p.is_host ? ' <span class="host-crown" title="Host">👑</span>' : ''}${isMe ? ' <span class="muted">(you)</span>' : ''}</div>
            <div class="seat-tag">Seat ${s + 1}${game.variant === '4p' ? ' · Team ' + (p.team + 1) : ''}${p.is_bot ? ' · BOT' : ''}</div>
          </div>
          ${canKick ? `<button class="btn danger small icon-only kick-btn" title="Kick" data-pid="${p.player_id}">✕</button>` : ''}
        `;
      } else {
        div.innerHTML = `
          <span class="muted seat-open-label">Seat ${s + 1} — open</span>
          <div class="seat-open-actions">
            ${canPickSeat ? `<button class="btn small sit-btn" data-seat="${s}">Sit here</button>` : ''}
            ${me.is_host ? `<button class="btn secondary small icon-only add-bot-btn" title="Add bot" data-seat="${s}">🤖</button>` : ''}
          </div>
        `;
      }
      seatGrid.appendChild(div);
    }
    seatGrid.querySelectorAll('.kick-btn').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const pid = parseInt(btn.dataset.pid, 10);
        if (!confirm('Kick this player from the table?')) return;
        try { await Api.post('kick_player.php', { game_id: gameId, target_player_id: pid }); }
        catch (e) { toast(e.message, { warn: true }); }
      });
    });
    seatGrid.querySelectorAll('.sit-btn').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const seat = parseInt(btn.dataset.seat, 10);
        try { await Api.post('take_seat.php', { game_id: gameId, seat }); }
        catch (e) { toast(e.message, { warn: true }); }
      });
    });
    seatGrid.querySelectorAll('.add-bot-btn').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const seat = parseInt(btn.dataset.seat, 10);
        try { await Api.post('add_bot.php', { game_id: gameId, seat }); }
        catch (e) { toast(e.message, { warn: true }); }
      });
    });

    const spectators = players.filter((p) => p.role === 'spectator');
    const specList = document.getElementById('spectatorList');
    specList.innerHTML = spectators.length
      ? '<span class="muted" style="font-size:.8rem;width:100%">Spectating: </span>' + spectators.map((p) => `<span class="chip">${escapeHtml(p.nickname)}</span>`).join('')
      : '';

    const seatedCount = Object.keys(bySeat).length;
    document.getElementById('seatCountLabel').textContent = `${seatedCount} / ${needed} seated`;
    const startBtn = document.getElementById('startBtn');
    startBtn.style.display = me.is_host ? 'block' : 'none';
    startBtn.disabled = seatedCount < needed || game.status !== 'lobby';

    cachedSettings = game.settings;
    renderSettings(game.settings, game.variant, me.is_host, game.status !== 'lobby');
  }

  function escapeHtml(s) {
    return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function renderChat(chat) {
    if (!chat.length) return;
    const log = document.getElementById('chatLog');
    chat.forEach((m) => {
      lastChatId = Math.max(lastChatId, m.id);
      const div = document.createElement('div');
      div.className = 'chat-msg' + (m.is_system ? ' system' : '');
      div.innerHTML = m.is_system ? escapeHtml(m.message) : `<span class="who">${escapeHtml(m.nickname)}:</span> ${escapeHtml(m.message)}`;
      log.appendChild(div);
      if (!m.is_system) Sound.chatPop();
    });
    log.scrollTop = log.scrollHeight;
  }

  async function poll() {
    try {
      const data = await Api.get('state.php', { game_id: gameId, since_chat_id: lastChatId });

      if (data.me.status === 'kicked') {
        if (!redirected) {
          redirected = true;
          Sound.kick();
          alert(`${data.game.host_nickname} kicked you from the table.`);
          location.href = 'index.php';
        }
        return;
      }
      if (data.me.status === 'left') { location.href = 'index.php'; return; }

      renderSeats(data);
      renderChat(data.chat);

      if (data.game.status !== 'lobby' && !redirected) {
        redirected = true;
        location.href = 'game.php?code=' + encodeURIComponent(data.game.code);
        return;
      }
    } catch (e) {
      // transient network hiccup — keep polling
    }
    setTimeout(poll, 1200);
  }
  poll();
})();
