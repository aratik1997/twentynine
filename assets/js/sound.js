/**
 * Tiny synthesized sound engine (Web Audio API). No external audio
 * files — every effect is a couple of oscillators/noise bursts, so the
 * whole game ships with zero binary assets and no licensing questions.
 */
const Sound = (() => {
  let ctx = null;
  let enabled = true;

  function ensureCtx() {
    if (!ctx) {
      const AC = window.AudioContext || window.webkitAudioContext;
      ctx = new AC();
    }
    if (ctx.state === 'suspended') ctx.resume();
    return ctx;
  }

  function setEnabled(v) { enabled = v; }
  function isEnabled() { return enabled; }

  function tone(freq, duration, { type = 'sine', gain = 0.18, delay = 0, glideTo = null } = {}) {
    if (!enabled) return;
    const c = ensureCtx();
    const osc = c.createOscillator();
    const amp = c.createGain();
    osc.type = type;
    osc.frequency.setValueAtTime(freq, c.currentTime + delay);
    if (glideTo) {
      osc.frequency.linearRampToValueAtTime(glideTo, c.currentTime + delay + duration);
    }
    amp.gain.setValueAtTime(0, c.currentTime + delay);
    amp.gain.linearRampToValueAtTime(gain, c.currentTime + delay + 0.01);
    amp.gain.exponentialRampToValueAtTime(0.001, c.currentTime + delay + duration);
    osc.connect(amp).connect(c.destination);
    osc.start(c.currentTime + delay);
    osc.stop(c.currentTime + delay + duration + 0.02);
  }

  function noiseBurst(duration, { gain = 0.12, delay = 0, filterFreq = 2200 } = {}) {
    if (!enabled) return;
    const c = ensureCtx();
    const bufferSize = c.sampleRate * duration;
    const buffer = c.createBuffer(1, bufferSize, c.sampleRate);
    const data = buffer.getChannelData(0);
    for (let i = 0; i < bufferSize; i++) data[i] = (Math.random() * 2 - 1) * (1 - i / bufferSize);
    const src = c.createBufferSource();
    src.buffer = buffer;
    const filter = c.createBiquadFilter();
    filter.type = 'lowpass';
    filter.frequency.value = filterFreq;
    const amp = c.createGain();
    amp.gain.setValueAtTime(gain, c.currentTime + delay);
    amp.gain.exponentialRampToValueAtTime(0.001, c.currentTime + delay + duration);
    src.connect(filter).connect(amp).connect(c.destination);
    src.start(c.currentTime + delay);
  }

  return {
    setEnabled, isEnabled,
    unlock() { ensureCtx(); },

    cardDraw() { noiseBurst(0.12, { gain: 0.10, filterFreq: 3200 }); },

    cardPlay() {
      noiseBurst(0.08, { gain: 0.14, filterFreq: 2600 });
      tone(520, 0.06, { type: 'triangle', gain: 0.06, delay: 0.02 });
    },

    trickWin() {
      tone(523.25, 0.12, { type: 'sine', gain: 0.16 });
      tone(659.25, 0.14, { type: 'sine', gain: 0.16, delay: 0.09 });
      tone(783.99, 0.2, { type: 'sine', gain: 0.16, delay: 0.18 });
    },

    bidPlace() { tone(300, 0.1, { type: 'square', gain: 0.08, glideTo: 420 }); },
    bidPass() { tone(220, 0.14, { type: 'sine', gain: 0.08, glideTo: 160 }); },

    trumpReveal() {
      // A little regal fanfare — this is 29's stand-in for a "big call" sound.
      [392, 493.88, 587.33, 783.99].forEach((f, i) => tone(f, 0.22, { type: 'sawtooth', gain: 0.10, delay: i * 0.1 }));
    },

    yourTurn() { tone(660, 0.09, { type: 'sine', gain: 0.12 }); tone(880, 0.12, { type: 'sine', gain: 0.10, delay: 0.09 }); },

    roundEndWin() {
      [523.25, 659.25, 783.99, 1046.5].forEach((f, i) => tone(f, 0.22, { type: 'triangle', gain: 0.13, delay: i * 0.12 }));
    },
    roundEndLose() {
      [392, 349.23, 293.66].forEach((f, i) => tone(f, 0.3, { type: 'sawtooth', gain: 0.1, delay: i * 0.14 }));
    },

    notify() { tone(740, 0.08, { type: 'sine', gain: 0.1 }); },
    kick() { tone(160, 0.3, { type: 'sawtooth', gain: 0.14, glideTo: 60 }); },
    chatPop() { tone(880, 0.05, { type: 'sine', gain: 0.06 }); },
  };
})();
