(function () {
  const gameId = window.GAME_ID;
  const SUIT_SYMBOL = { S: '♠', H: '♥', D: '♦', C: '♣' };
  const SUIT_NAME = { S: 'Spades', H: 'Hearts', D: 'Diamonds', C: 'Clubs' };
  const RED_SUITS = ['H', 'D'];

  let lastChatId = 0;
  let redirectedKick = false;
  let prev = null; // previous poll snapshot, for diffing -> sounds/animation
  let lastTrickCardKeys = new Set(); // seat:card keys already on the table -> only newly played cards get play-anim
  let activeTab = 'action';
  let soundOn = localStorage.getItem('tn_sound') !== 'off';
  Sound.setEnabled(soundOn);
  document.getElementById('soundToggleBtn').textContent = soundOn ? '🔊' : '🔇';

  // ---------------------------------------------------------------- utils
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  function cardRank(card) { return card.slice(1); }
  function cardSuit(card) { return card.slice(0, 1); }
  function isRed(card) { return RED_SUITS.includes(cardSuit(card)); }
  function cardEl(card, { faceDown = false, small = false } = {}) {
    const div = document.createElement('div');
    div.className = 'pcard' + (faceDown ? ' back' : '') + (isRed(card) && !faceDown ? ' red' : '') + (small ? ' mini' : '');
    if (!faceDown) {
      div.innerHTML = `<span class="rank">${cardRank(card)}${SUIT_SYMBOL[cardSuit(card)]}</span>
                        <span class="suit-big">${SUIT_SYMBOL[cardSuit(card)]}</span>
                        <span class="rank bottom">${cardRank(card)}${SUIT_SYMBOL[cardSuit(card)]}</span>`;
    }
    return div;
  }

  // relative seat position (0 = me/bottom) -> CSS position class
  function posClassFor(numSeats, rel) {
    if (numSeats === 4) return ['pos-bottom', 'pos-left', 'pos-top', 'pos-right'][rel];
    return ['pos-bottom', 'pos-topright', 'pos-topleft'][rel];
  }

  // ---------------------------------------------------------------- tabs
  document.querySelectorAll('.side-tabs button').forEach((btn) => {
    btn.addEventListener('click', () => {
      activeTab = btn.dataset.tab;
      document.querySelectorAll('.side-tabs button').forEach((b) => b.classList.toggle('active', b === btn));
      document.querySelectorAll('.side-tab-panel').forEach((p) => p.classList.toggle('active', p.dataset.tab === activeTab));
    });
  });

  document.getElementById('soundToggleBtn').addEventListener('click', () => {
    soundOn = !soundOn;
    localStorage.setItem('tn_sound', soundOn ? 'on' : 'off');
    Sound.setEnabled(soundOn);
    Sound.unlock();
    document.getElementById('soundToggleBtn').textContent = soundOn ? '🔊' : '🔇';
  });

  document.getElementById('leaveBtn').addEventListener('click', async () => {
    if (!confirm('Leave the table? You will be skipped starting next round.')) return;
    try { await Api.post('leave_game.php', { game_id: gameId }); } catch (e) {}
    location.href = 'index.php';
  });

  document.getElementById('rulesBtn').addEventListener('click', openRulesModal);
  async function openRulesModal() {
    const root = document.getElementById('rulesModalRoot');
    root.innerHTML = '<div class="modal-backdrop"><div class="modal-box"><button class="btn secondary small modal-close">✕ Close</button><div id="rulesContent">Loading…</div></div></div>';
    root.querySelector('.modal-close').addEventListener('click', () => root.innerHTML = '');
    root.querySelector('.modal-backdrop').addEventListener('click', (e) => { if (e.target.classList.contains('modal-backdrop')) root.innerHTML = ''; });
    try {
      const res = await fetch('rules.php?embed=1');
      document.getElementById('rulesContent').innerHTML = await res.text();
    } catch (e) { document.getElementById('rulesContent').textContent = 'Could not load rules.'; }
  }

  document.getElementById('chatSendBtn').addEventListener('click', sendChat);
  document.getElementById('chatInput').addEventListener('keyup', (e) => { if (e.key === 'Enter') sendChat(); });
  async function sendChat() {
    const input = document.getElementById('chatInput');
    const msg = input.value.trim();
    if (!msg) return;
    input.value = '';
    try { await Api.post('chat_send.php', { game_id: gameId, message: msg }); } catch (e) { toast(e.message, { warn: true }); }
  }

  document.getElementById('nextRoundBtn').addEventListener('click', async () => {
    try { await Api.post('next_round.php', { game_id: gameId }); } catch (e) { toast(e.message, { warn: true }); }
  });

  // ---------------------------------------------------------------- render
  function renderSeats(data) {
    const { game, state, players, me } = data;
    const numSeats = game.needed_seats;
    document.querySelectorAll('.seat-pos').forEach((n) => n.remove());
    const oval = document.getElementById('tableOval');
    const bySeat = {};
    players.forEach((p) => { if (p.seat !== null) bySeat[p.seat] = p; });

    const mySeat = me.seat !== null ? me.seat : 0;
    for (let s = 0; s < numSeats; s++) {
      const rel = (s - mySeat + numSeats) % numSeats;
      const p = bySeat[s];
      const div = document.createElement('div');
      div.className = 'seat-pos ' + posClassFor(numSeats, rel);
      div.dataset.seat = s;
      const isTurn = state && state.turn_seat === s && ['bidding', 'trump_select', 'playing'].includes(state.phase);
      const isDealer = state && state.dealer_seat === s;
      const cardsLeft = (data.other_hand_counts && data.other_hand_counts[s] !== undefined)
        ? data.other_hand_counts[s]
        : (s === mySeat ? (data.my_hand || []).length : null);

      const group = pileGroupFor(game, s);
      const groupTricks = state ? (state.trick_history || []).filter((t) => pileGroupFor(game, t.winner) === group) : [];
      const tricksWon = groupTricks.length;
      const pilePoints = groupTricks.reduce((sum, t) => sum + t.points, 0);
      const showTally = state && tricksWon > 0 && ['playing', 'round_end'].includes(state.phase);
      const hasMarriage = state && state.marriage_status !== 'none' && state.marriage_seat === s;

      div.innerHTML = `
        <div class="seat-badge${isTurn ? ' my-turn' : ''}">
          <div class="name">${p ? (p.is_bot ? '🤖 ' : '') + escapeHtml(p.nickname) : '<span class="muted">Empty</span>'}
            ${p && game.variant === '4p' ? `<span class="team-dot team-${p.team}"></span>` : ''}
            ${isDealer ? '<span title="Dealer">🃏D</span>' : ''}
            ${hasMarriage ? `<span title="Marriage (${state.marriage_status})">💍</span>` : ''}
          </div>
          <div class="sub">${p ? (s === mySeat ? 'You' : (cardsLeft !== null ? cardsLeft + ' cards' : '')) : ''}</div>
          ${showTally ? `<button class="trick-tally" data-group="${group}" title="Click to see this pile's tricks">🂠 ${tricksWon} · ${pilePoints} pt${pilePoints === 1 ? '' : 's'}</button>` : ''}
        </div>
        ${p && s !== mySeat ? `<div class="hand-back">${renderBacks(cardsLeft)}</div>` : ''}
      `;
      oval.appendChild(div);
    }
    oval.querySelectorAll('.trick-tally').forEach((btn) => {
      btn.addEventListener('click', () => openTrickPileModal(data, parseInt(btn.dataset.group, 10)));
    });
  }
  function renderBacks(n) {
    n = Math.min(n || 0, 8);
    let html = '';
    for (let i = 0; i < n; i++) html += '<div class="mini-card-back"></div>';
    return html;
  }

  // Animate a just-played card flying from the player's seat (or, for your
  // own card, straight out of your hand tray) to its landing slot on the
  // table. Falls back to the plain CSS fade-in (play-anim) if GSAP or the
  // source element isn't available (e.g. a fresh page load mid-trick).
  function animateCardPlay(slotEl, cardElement, seat, mySeat, cardCode, delayMs = 0) {
    if (!window.gsap) return;
    const sourceEl = seat === mySeat
      ? document.querySelector('#handTray [data-card="' + CSS.escape(cardCode) + '"]')
      : document.querySelector('.seat-pos[data-seat="' + seat + '"] .seat-badge');
    if (!sourceEl) return;

    const finalRect = slotEl.getBoundingClientRect();
    const sourceRect = sourceEl.getBoundingClientRect();
    const dx = (sourceRect.left + sourceRect.width / 2) - (finalRect.left + finalRect.width / 2);
    const dy = (sourceRect.top + sourceRect.height / 2) - (finalRect.top + finalRect.height / 2);

    slotEl.classList.remove('play-anim'); // GSAP takes over the motion instead
    gsap.fromTo(cardElement,
      { x: dx, y: dy, scale: 0.7, opacity: 0.5, rotation: Math.random() * 16 - 8 },
      { x: 0, y: 0, scale: 1, opacity: 1, rotation: 0, duration: 0.5, ease: 'power2.out', delay: delayMs / 1000 }
    );
  }

  function buildTrickCardSlot(entry, mySeat, numSeats, players) {
    const rel = (entry.seat - mySeat + numSeats) % numSeats;
    const slot = document.createElement('div');
    slot.className = 'trick-card-slot ' + posClassFor(numSeats, rel);
    const label = document.createElement('div');
    label.className = 'trick-card-label';
    label.textContent = entry.seat === mySeat ? 'You' : seatName(players, entry.seat);
    slot.appendChild(label);
    const cardElement = cardEl(entry.card);
    slot.appendChild(cardElement);
    return { slot, cardElement };
  }

  // Shared renderer for a set of trick-card entries (either the live,
  // in-progress state.table_cards, or a just-completed trick pulled from
  // trick_history). Cards not seen before (per lastTrickCardKeys) fly in
  // from their player's position — staggered one after another so that
  // when several land between two polls (e.g. bots auto-playing in a
  // chain), they visibly play out in order instead of all popping in at
  // once ("skipping").
  function renderTrickEntries(entries, mySeat, numSeats, players, area) {
    const currentKeys = new Set();
    entries.forEach((entry) => currentKeys.add(entry.seat + ':' + entry.card));

    area.innerHTML = '';
    let staggerIndex = 0;
    entries.forEach((entry) => {
      const key = entry.seat + ':' + entry.card;
      const isNew = !lastTrickCardKeys.has(key);
      const { slot, cardElement } = buildTrickCardSlot(entry, mySeat, numSeats, players);
      if (isNew) slot.classList.add('play-anim');
      area.appendChild(slot);
      if (isNew) {
        animateCardPlay(slot, cardElement, entry.seat, mySeat, entry.card, staggerIndex * 280);
        staggerIndex++;
      }
    });
    lastTrickCardKeys = currentKeys;
  }

  // A trick can fully resolve (all 4 cards played + winner decided) inside
  // a SINGLE server request, when the last player(s) needed are bots that
  // auto-play in the same call that a human's action triggers. When that
  // happens no client's poll ever observes the table with all 4 cards on
  // it via state.table_cards (it's already been cleared by the time the
  // next poll lands). So instead of trusting whatever's currently in the
  // DOM, explicitly render the just-completed trick from trick_history —
  // that guarantees all 4 cards are shown (with their play animation) for
  // the reveal-pause every time.
  function renderCompletedTrick(data, trick) {
    const { game, me, players } = data;
    const area = document.getElementById('trickArea');
    const numSeats = game.needed_seats;
    const mySeat = me.seat !== null ? me.seat : 0;
    renderTrickEntries(trick.cards || [], mySeat, numSeats, players, area);
  }

  function renderTrick(data) {
    const { game, state, me, players } = data;
    const area = document.getElementById('trickArea');
    const numSeats = game.needed_seats;
    const mySeat = me.seat !== null ? me.seat : 0;
    if (!state) { area.innerHTML = ''; lastTrickCardKeys = new Set(); return; }

    renderTrickEntries(state.table_cards || [], mySeat, numSeats, players, area);

    const info = document.getElementById('centerInfo');
    const trumpTxt = state.trump_revealed
      ? `Trump: ${SUIT_SYMBOL[state.trump_suit]} ${SUIT_NAME[state.trump_suit]}`
      : (state.trump_suit && me.seat === state.bidder_seat ? `Trump (secret): ${SUIT_SYMBOL[state.trump_suit]}` : (state.phase === 'playing' || state.phase === 'trump_select' ? 'Trump hidden' : ''));

    let pointsTxt = '';
    if (state.phase === 'playing' && state.points) {
      if (game.variant === '4p') {
        const t0 = players.filter((p) => p.team === 0).reduce((sum, p) => sum + (state.points[p.seat] || 0), 0);
        const t1 = players.filter((p) => p.team === 1).reduce((sum, p) => sum + (state.points[p.seat] || 0), 0);
        pointsTxt = `<br>Team 1: ${t0} pts · Team 2: ${t1} pts`;
      } else {
        pointsTxt = '<br>' + players.filter((p) => p.seat !== null).sort((a, b) => a.seat - b.seat)
          .map((p) => `${escapeHtml(p.nickname)}: ${state.points[p.seat] || 0}`).join(' · ') + ' pts';
      }
    }

    info.innerHTML = state.phase && state.phase !== 'waiting'
      ? `Round ${state.round_no} · Trick ${Math.min(state.trick_no, 8)}/8<br>${trumpTxt}${pointsTxt}`
      : '';
  }

  function renderHand(data) {
    const { state, me } = data;
    const tray = document.getElementById('handTray');
    const hand = data.my_hand || [];
    const legal = new Set(data.legal_cards || []);
    const canPlay = state && state.phase === 'playing' && state.turn_seat === me.seat;

    const existingCodes = Array.from(tray.children).map((c) => c.dataset.card);
    tray.innerHTML = '';
    hand.forEach((card) => {
      const el = cardEl(card);
      el.dataset.card = card;
      if (!existingCodes.includes(card)) el.classList.add('deal-anim');
      if (canPlay) {
        const ok = legal.has(card);
        el.classList.add(ok ? 'playable' : 'illegal');
        if (ok) el.addEventListener('click', () => playCard(card));
      }
      tray.appendChild(el);
    });
  }

  async function playCard(card) {
    try {
      Sound.cardPlay();
      await Api.post('play_card.php', { game_id: gameId, card });
    } catch (e) { toast(e.message, { warn: true }); }
  }

  function seatName(players, seat) {
    const p = players.find((p) => p.seat === seat);
    return p ? p.nickname : `Seat ${seat + 1}`;
  }

  /** 4p: tricks belong to a team (partners share a pile). 3p: each seat is its own pile. */
  function pileGroupFor(game, seat) {
    return game.variant === '4p' ? seat % 2 : seat;
  }

  function renderTrickRows(tricks, players) {
    return tricks.map((t) => {
      const cardsHtml = t.cards.map((c) => {
        const label = escapeHtml(seatName(players, c.seat));
        const mini = cardEl(c.card, { small: true });
        return `<div class="trick-row-card-wrap"><div class="trick-row-card-label">${label}</div>${mini.outerHTML}</div>`;
      }).join('');
      const winnerLabel = escapeHtml(seatName(players, t.winner));
      return `<div class="trick-row">
        <div class="trick-row-header"><b>Trick ${t.trick_no}</b> — <span class="gold-text">${winnerLabel}</span> won · +${t.points} pt${t.points === 1 ? '' : 's'}</div>
        <div class="trick-row-cards">${cardsHtml}</div>
      </div>`;
    }).join('');
  }

  function openTrickPileModal(data, groupKey) {
    const { game, state, players } = data;
    const tricks = (state.trick_history || []).filter((t) => pileGroupFor(game, t.winner) === groupKey);
    const title = game.variant === '4p'
      ? `Team ${groupKey + 1}'s tricks`
      : `${escapeHtml(seatName(players, groupKey))}'s tricks`;
    const root = document.getElementById('rulesModalRoot');
    const body = tricks.length
      ? '<div class="trick-history-list">' + renderTrickRows(tricks, players) + '</div>'
      : '<p class="muted">No tricks won yet this round.</p>';
    root.innerHTML = `<div class="modal-backdrop"><div class="modal-box"><button class="btn secondary small modal-close">✕ Close</button><h2 class="gold-text">${title}</h2>${body}</div></div>`;
    root.querySelector('.modal-close').addEventListener('click', () => root.innerHTML = '');
    root.querySelector('.modal-backdrop').addEventListener('click', (e) => { if (e.target.classList.contains('modal-backdrop')) root.innerHTML = ''; });
  }

  function renderActionPanel(data) {
    const { game, state, me, players } = data;
    const panel = document.getElementById('actionPanel');
    if (!state) { panel.innerHTML = '<p class="muted">Waiting for the round to begin…</p>'; return; }
    const iAmPlayer = me.role === 'player';

    if (state.phase === 'bidding') {
      const myTurn = iAmPlayer && state.turn_seat === me.seat;
      const min = state.highest_bid !== null ? state.highest_bid + 1 : game.settings.min_bid;
      const max = game.settings.max_bid;
      if (!myTurn) {
        panel.dataset.renderKey = '';
        panel.innerHTML = `<p>Bidding in progress…</p><p class="muted">Waiting on <b>${escapeHtml(seatName(players, state.turn_seat))}</b>.</p>`;
        return;
      }
      // Every poll re-runs this whole render; without this guard, the
      // stepper would rebuild (and its in-progress value reset to `min`)
      // every ~1.2s even though nothing about the turn has actually changed.
      const bidKey = state.round_no + ':' + state.turn_seat + ':' + state.highest_bid;
      if (panel.dataset.renderKey === bidKey) {
        return;
      }
      panel.dataset.renderKey = bidKey;
      panel.innerHTML = `
        <div class="bid-controls">
          <p>Your bid (current high: ${state.highest_bid ?? '—'})</p>
          <div class="bid-stepper">
            <button class="btn small secondary" id="bidDown">−</button>
            <span class="value" id="bidValue">${min}</span>
            <button class="btn small secondary" id="bidUp">+</button>
          </div>
          <button class="btn block" id="bidSubmit">Bid</button>
          <button class="btn secondary block" id="bidPass">Pass</button>
        </div>`;
      let val = min;
      const valEl = () => document.getElementById('bidValue');
      document.getElementById('bidDown').onclick = () => { val = Math.max(min, val - 1); valEl().textContent = val; };
      document.getElementById('bidUp').onclick = () => { val = Math.min(max, val + 1); valEl().textContent = val; };
      document.getElementById('bidSubmit').onclick = async () => {
        try { Sound.bidPlace(); await Api.post('bid.php', { game_id: gameId, value: val }); }
        catch (e) { toast(e.message, { warn: true }); }
      };
      document.getElementById('bidPass').onclick = async () => {
        try { Sound.bidPass(); await Api.post('pass_bid.php', { game_id: gameId }); }
        catch (e) { toast(e.message, { warn: true }); }
      };
      return;
    }

    if (state.phase === 'trump_select') {
      // Double/Redouble window: after the call (bidding's over) and before
      // the second batch of 4 cards is dealt — which happens the instant
      // the bidder picks trump — so this is the only phase either applies.
      const bidderTeam = game.variant === '4p' ? state.bidder_seat % 2 : null;
      const myTeam = game.variant === '4p' ? me.seat % 2 : null;
      const canDouble = game.settings.allow_double && state.doubled_by === null
        && game.variant === '4p' && myTeam !== null && myTeam !== bidderTeam;
      const canRedouble = game.settings.allow_redouble && state.doubled_by !== null
        && state.redoubled_by === null && myTeam === bidderTeam;
      let extra = '';
      if (canDouble) extra += '<button class="btn small secondary" id="doubleBtn">📣 Double</button>';
      if (canRedouble) extra += '<button class="btn small secondary" id="redoubleBtn">📣 Re-Double</button>';
      const statusLine = (state.doubled_by !== null || state.redoubled_by !== null)
        ? `<p>${state.doubled_by !== null ? '<b>Doubled</b>' : ''}${state.redoubled_by !== null ? ' · <b>Redoubled</b>' : ''}</p>`
        : '';

      if (me.seat !== state.bidder_seat) {
        const waitingMsg = state.trump_chosen
          ? `<b>${escapeHtml(seatName(players, state.bidder_seat))}</b> has selected trump — double window is open.`
          : `<b>${escapeHtml(seatName(players, state.bidder_seat))}</b> won the bid at ${state.highest_bid} and is choosing trump…`;
        panel.innerHTML = `
          <p>${waitingMsg}</p>
          ${statusLine}
          <div class="row wrap">${extra}</div>
        `;
      } else if (!state.trump_chosen) {
        panel.innerHTML = `
          <p>You won the bid at <b>${state.highest_bid}</b>. Choose trump (kept secret until revealed in play):</p>
          <div class="trump-pick">
            ${['S','H','D','C'].map((s) => `<button data-suit="${s}" class="${RED_SUITS.includes(s) ? 'suit-red' : ''}">${SUIT_SYMBOL[s]}</button>`).join('')}
          </div>
          ${game.settings.single_hand ? '<button class="btn secondary block mt-1" id="singleHandBtn">Declare Single Hand</button>' : ''}
        `;
        panel.querySelectorAll('.trump-pick button').forEach((b) => b.addEventListener('click', async () => {
          try { await Api.post('select_trump.php', { game_id: gameId, suit: b.dataset.suit }); }
          catch (e) { toast(e.message, { warn: true }); }
        }));
        const shBtn = document.getElementById('singleHandBtn');
        if (shBtn) shBtn.addEventListener('click', async () => {
          try { await Api.post('declare_single_hand.php', { game_id: gameId }); toast('Single Hand declared!'); }
          catch (e) { toast(e.message, { warn: true }); }
        });
      } else {
        panel.innerHTML = `
          <p>Trump selected. Double window is open — opponents may call Double now.</p>
          ${statusLine}
          <div class="row wrap">${extra}</div>
          <button class="btn block mt-1" id="proceedBtn">▶ Deal Remaining Cards &amp; Play</button>
        `;
        const pBtn = document.getElementById('proceedBtn');
        if (pBtn) pBtn.addEventListener('click', async () => {
          try { await Api.post('proceed_to_play.php', { game_id: gameId }); }
          catch (e) { toast(e.message, { warn: true }); }
        });
      }
      const dBtn = document.getElementById('doubleBtn');
      if (dBtn) dBtn.addEventListener('click', async () => { try { await Api.post('double.php', { game_id: gameId }); } catch (e) { toast(e.message, { warn: true }); } });
      const rBtn = document.getElementById('redoubleBtn');
      if (rBtn) rBtn.addEventListener('click', async () => { try { await Api.post('redouble.php', { game_id: gameId }); } catch (e) { toast(e.message, { warn: true }); } });
      return;
    }

    if (state.phase === 'playing') {
      let extra = '';
      if (!state.trump_revealed) {
        extra += '<button class="btn small secondary" id="revealTrumpBtn">👁 Reveal Trump</button>';
      }
      panel.innerHTML = `
        <p>Bid: <b>${state.highest_bid}</b> by ${escapeHtml(seatName(players, state.bidder_seat))}
          ${state.doubled_by !== null ? ' · <b>Doubled</b>' : ''}${state.redoubled_by !== null ? ' · <b>Redoubled</b>' : ''}</p>
        <p class="muted">${state.turn_seat === me.seat ? 'Your turn — play a card.' : 'Waiting on ' + escapeHtml(seatName(players, state.turn_seat)) + '…'}</p>
        <div class="row wrap">${extra}</div>
      `;
      const revBtn = document.getElementById('revealTrumpBtn');
      if (revBtn) revBtn.addEventListener('click', async () => { try { await Api.post('reveal_trump.php', { game_id: gameId }); } catch (e) { toast(e.message, { warn: true }); } });
      return;
    }

    if (state.phase === 'round_end') {
      // The result is fully determined by round_no and won't change again
      // until the next round is dealt — skip re-rendering on every poll so
      // a scrolled trick-history-list doesn't keep snapping back to the top.
      const roundEndKey = 'round_end:' + state.round_no;
      if (panel.dataset.renderKey === roundEndKey) {
        return;
      }
      panel.dataset.renderKey = roundEndKey;
      const r = state.last_result || {};
      let html = `<h3 class="gold-text">Round ${state.round_no} Result</h3>`;
      html += `<p>Taking call: <b>${escapeHtml(seatName(players, r.bidder_seat))}</b> with <b>${r.raw_bid ?? r.bid_value}</b></p>`;
      if (r.marriage_side) {
        const label = r.marriage_seat === me.seat ? 'You' : escapeHtml(seatName(players, r.marriage_seat));
        const sign = r.marriage_side === 'bidder' ? '−4' : '+4';
        html += `<p>💍 Marriage: ${label} (${r.marriage_side === 'bidder' ? "bidder's side" : "opponent's side"}) → call ${sign} = <b>${r.bid_value}</b></p>`;
      }
      html += `<p>Call: <b>${r.bid_value}</b> — <b>${r.success ? 'Success ✅' : 'Failed ❌'}</b>${r.multiplier > 1 ? ' (x' + r.multiplier + ')' : ''}</p>`;
      if (game.variant === '4p') {
        html += `<p>Team 1: ${r.team_points[0]} pts · Team 2: ${r.team_points[1]} pts</p>`;
      } else {
        html += '<p>' + players.filter(p=>p.seat!==null).sort((a,b)=>a.seat-b.seat).map(p => `${escapeHtml(p.nickname)}: ${r.points[p.seat] ?? 0} pts`).join(' · ') + '</p>';
      }
      html += `<p class="muted">${me.is_host ? 'Click "Deal Next Round" above when ready.' : 'Waiting for the host to deal the next round…'}</p>`;

      const tricks = state.trick_history || [];
      if (tricks.length) {
        html += '<h3 class="gold-text" style="font-size:.95rem">All tricks this round</h3><div class="trick-history-list">';
        html += renderTrickRows(tricks, players);
        html += '</div>';
      }

      panel.innerHTML = html;
      return;
    }

    if (state.phase === 'finished') {
      panel.innerHTML = '<h3 class="gold-text">Match complete!</h3><p>Check the Score tab for final standings.</p>';
      return;
    }

    panel.innerHTML = '';
  }

  function renderScoreboard(data) {
    const { game, players, me } = data;
    const order = game.settings.scoreboard_order === 'asc' ? 1 : -1;
    const thead = document.querySelector('#scoreTable thead');
    const tbody = document.querySelector('#scoreTable tbody');

    if (game.variant === '4p') {
      // Teammates always carry the same cumulative score (deltas apply to
      // both at once), so group by team and show one combined score.
      const teams = [0, 1].map((t) => {
        const members = players.filter((p) => p.seat !== null && p.team === t);
        return { team: t, members, score: members[0] ? members[0].score : 0 };
      });
      teams.sort((a, b) => order * (a.score - b.score));

      thead.innerHTML = '';
      tbody.innerHTML = teams.map((tm, i) => `
        <tr class="team-header${i === 0 ? ' winner' : ''}">
          <td colspan="2"><span class="team-dot team-${tm.team}"></span> Team ${tm.team + 1} — <span class="score-val">${tm.score}</span></td>
        </tr>
        ${tm.members.map((p) => `
        <tr class="${p.player_id === me.player_id ? 'me' : ''}">
          <td colspan="2">${escapeHtml(p.nickname)}${p.is_host ? ' 👑' : ''}${p.is_bot ? ' 🤖' : ''}</td>
        </tr>`).join('')}
      `).join('');
    } else {
      const rows = players.filter((p) => p.seat !== null).slice().sort((a, b) => order * (a.score - b.score));
      thead.innerHTML = `<tr><th>#</th><th>Player</th><th>Score</th></tr>`;
      tbody.innerHTML = rows.map((p, i) => `
        <tr class="${p.player_id === me.player_id ? 'me' : ''}${i === 0 ? ' winner' : ''}">
          <td>${i + 1}</td>
          <td>${escapeHtml(p.nickname)}${p.is_host ? ' 👑' : ''}${p.is_bot ? ' 🤖' : ''}</td>
          <td class="score-val">${p.score}</td>
        </tr>`).join('');
    }

    const bidLog = document.getElementById('bidHistoryList');
    if (data.state && data.state.bid_history) {
      bidLog.innerHTML = data.state.bid_history.map((b) => {
        const name = escapeHtml(seatName(players, b.seat));
        if (b.action === 'pass') return `<div>${name} passed</div>`;
        if (b.action === 'forced_bid') return `<div>${name} forced to bid ${b.value}</div>`;
        return `<div>${name} bid ${b.value}</div>`;
      }).join('');
    }
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
      if (!m.is_system && activeTab !== 'chat') Sound.chatPop();
    });
    log.scrollTop = log.scrollHeight;
  }

  function renderStatusBar(data) {
    const { game, state, me } = data;
    const bar = document.getElementById('statusBar');
    const banner = document.getElementById('turnBanner');
    let text = '';
    if (!state) text = 'Waiting for the game to start…';
    else if (state.phase === 'bidding') text = 'Bidding';
    else if (state.phase === 'trump_select') text = 'Choosing trump';
    else if (state.phase === 'playing') text = `Playing — Trick ${Math.min(state.trick_no, 8)}/8`;
    else if (state.phase === 'round_end') text = 'Round complete';
    else if (state.phase === 'finished') text = 'Match complete';
    bar.textContent = text;

    if (state && ['bidding', 'trump_select', 'playing'].includes(state.phase)) {
      const whoTxt = state.turn_seat === me.seat ? 'Your turn!' : `${seatName(data.players, state.turn_seat)}'s turn`;
      const secs = state.turn_seconds_left;
      banner.textContent = secs !== null && secs !== undefined ? `${whoTxt} (${secs}s)` : whoTxt;
      banner.classList.toggle('urgent', secs !== null && secs !== undefined && secs <= 10);
      banner.style.display = 'block';
    } else {
      banner.style.display = 'none';
      banner.classList.remove('urgent');
    }

    document.getElementById('hostControls').style.display = me.is_host ? 'flex' : 'none';
    document.getElementById('nextRoundBtn').style.display = (me.is_host && state && state.phase === 'round_end') ? 'inline-block' : 'none';
  }

  // ---------------------------------------------------------------- sounds/diffing
  function reactToChanges(data) {
    const { state, me } = data;
    if (!state) { prev = data; return; }
    if (prev && prev.state) {
      const p = prev.state;
      if (p.trick_no < state.trick_no && (p.trick_history || []).length < (state.trick_history || []).length) {
        Sound.trickWin();
        const lastTrick = state.trick_history[state.trick_history.length - 1];
        if (lastTrick) {
          const winnerLabel = lastTrick.winner === me.seat ? 'You' : escapeHtml(seatName(data.players, lastTrick.winner));
          toast(`${winnerLabel} won the trick (+${lastTrick.points} pt${lastTrick.points === 1 ? '' : 's'})`);
        }
      }
      if (p.phase === 'bidding' && state.phase !== 'bidding' && state.bidder_seat !== null) {
        const label = state.bidder_seat === me.seat ? 'You' : escapeHtml(seatName(data.players, state.bidder_seat));
        toast(`📣 ${label} took the call with ${state.highest_bid}!`);
      }
      if (!p.trump_revealed && state.trump_revealed) {
        Sound.trumpReveal();
        toast(`👁 Trump revealed: ${SUIT_SYMBOL[state.trump_suit]} ${SUIT_NAME[state.trump_suit]}!`);
      }
      if (p.doubled_by === null && state.doubled_by !== null) {
        const label = state.doubled_by === me.seat ? 'You' : escapeHtml(seatName(data.players, state.doubled_by));
        toast(`📣 ${label} called Double!`, { warn: true });
      }
      if (p.redoubled_by === null && state.redoubled_by !== null) {
        const label = state.redoubled_by === me.seat ? 'You' : escapeHtml(seatName(data.players, state.redoubled_by));
        toast(`📣 ${label} called Re-Double!`, { warn: true });
      }
      if (p.marriage_status === 'none' && state.marriage_status === 'half') {
        const label = state.marriage_seat === me.seat ? 'You' : escapeHtml(seatName(data.players, state.marriage_seat));
        toast(`💍 ${label} announced Marriage — half!`);
      }
      if (p.marriage_status !== 'full' && state.marriage_status === 'full') {
        const label = state.marriage_seat === me.seat ? 'You' : escapeHtml(seatName(data.players, state.marriage_seat));
        const bidderTeam = data.game.variant === '4p' ? state.bidder_seat % 2 : state.bidder_seat;
        const marriageTeam = data.game.variant === '4p' ? state.marriage_seat % 2 : state.marriage_seat;
        const onBiddingSide = marriageTeam === bidderTeam;
        const adjusted = onBiddingSide
          ? Math.max(16, state.highest_bid - 4)
          : Math.min(28, state.highest_bid + 4);
        toast(`💍 ${label} completed Marriage — full! Call adjusted to ${adjusted}.`);
      }
      if (p.turn_seat !== state.turn_seat && state.turn_seat === me.seat && ['bidding', 'playing'].includes(state.phase)) {
        Sound.yourTurn();
      }
      if (p.phase !== 'round_end' && state.phase === 'round_end') {
        const r = state.last_result;
        const won = data.game.variant === '4p' ? (r.success === (r.bidder_seat % 2 === me.seat % 2)) : null;
        if (data.game.variant === '4p') { won ? Sound.roundEndWin() : Sound.roundEndLose(); }
        else { Sound.roundEndWin(); }
      }
    }
    prev = data;
  }

  // A trick just finished: sweep the cards currently sitting in the trick
  // area off toward the winner before the next render clears them.
  function collectTrickFlourish() {
    const area = document.getElementById('trickArea');
    const nodes = Array.from(area.querySelectorAll('.trick-card-slot'));
    if (!nodes.length || !window.gsap) return;
    gsap.to(nodes, { y: -55, scale: 0.5, opacity: 0, duration: 0.42, stagger: 0.05, ease: 'power1.in' });
  }

  function renderRest(data) {
    renderTrick(data);
    renderHand(data);
    renderActionPanel(data);
    renderScoreboard(data);
    renderChat(data.chat);
    renderStatusBar(data);
  }

  // ---------------------------------------------------------------- poll
  function scheduleNextPoll(delayMs = 1200) {
    setTimeout(poll, delayMs);
  }

  async function poll() {
    try {
      const data = await Api.get('state.php', { game_id: gameId, since_chat_id: lastChatId });

      if (data.me.status === 'kicked') {
        if (!redirectedKick) {
          redirectedKick = true;
          Sound.kick();
          alert(`${data.game.host_nickname} kicked you from the table.`);
          location.href = 'index.php';
        }
        return;
      }
      if (data.me.status === 'left') { location.href = 'index.php'; return; }
      if (data.game.status === 'lobby') { location.href = 'lobby.php?code=' + encodeURIComponent(data.game.code); return; }

      const trickJustCompleted = !!(prev && prev.state && data.state
        && prev.state.trick_no < data.state.trick_no
        && (prev.state.trick_history || []).length < (data.state.trick_history || []).length);

      reactToChanges(data);
      renderSeats(data);

      if (trickJustCompleted) {
        // Render the just-completed trick straight from trick_history (not
        // whatever's currently in the DOM) — when the trailing card(s) of a
        // trick were auto-played by bots inside another player's request,
        // this client's DOM may never have shown all 4 cards otherwise.
        const history = data.state.trick_history || [];
        const lastTrick = history[history.length - 1];
        if (lastTrick) renderCompletedTrick(data, lastTrick);

        // Hold the finished trick fully visible (all 4 cards) for the
        // configured pause before sweeping it away and moving on — polling
        // pauses too, so nothing overwrites the held display mid-pause.
        const holdSeconds = Math.max(3, (data.game.settings && data.game.settings.trick_reveal_seconds) || 3);
        setTimeout(() => {
          collectTrickFlourish();
          setTimeout(() => { renderRest(data); scheduleNextPoll(); }, 420);
        }, holdSeconds * 1000);
        return;
      }

      renderRest(data);
    } catch (e) {
      // transient — keep polling
    }
    scheduleNextPoll();
  }
  poll();
})();
