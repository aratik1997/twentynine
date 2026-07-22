/** Small fetch wrapper shared by every page. */
const Api = {
  async post(path, body) {
    const res = await fetch(`api/${path}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {}),
    });
    const data = await res.json().catch(() => ({ ok: false, error: 'Bad response from server.' }));
    if (!res.ok || !data.ok) throw new Error(data.error || 'Request failed.');
    return data;
  },
  async get(path, params) {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    const res = await fetch(`api/${path}${qs}`);
    const data = await res.json().catch(() => ({ ok: false, error: 'Bad response from server.' }));
    if (!res.ok || !data.ok) throw new Error(data.error || 'Request failed.');
    return data;
  },
};

function toast(message, opts = {}) {
  let stack = document.querySelector('.toast-stack');
  if (!stack) {
    stack = document.createElement('div');
    stack.className = 'toast-stack';
    document.body.appendChild(stack);
  }
  const el = document.createElement('div');
  el.className = 'toast' + (opts.warn ? ' warn' : '');
  el.textContent = message;
  stack.appendChild(el);
  setTimeout(() => el.remove(), opts.duration || 4200);
}
