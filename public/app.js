// Lájk gomb (oldal-újratöltés nélkül), karakterszámláló, megerősítő kérdések.
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('[data-like]');
  if (!btn) return;
  e.preventDefault();
  if (btn.disabled) return;
  btn.disabled = true;
  try {
    const res = await fetch(`/iras/${encodeURIComponent(btn.dataset.like)}/lajk`, {
      method: 'POST',
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    if (!res.ok) throw new Error(res.status);
    const { liked, count } = await res.json();
    // ugyanannak az írásnak minden gombját frissítjük
    document.querySelectorAll(`[data-like="${CSS.escape(btn.dataset.like)}"]`).forEach((b) => {
      b.classList.toggle('liked', liked);
      b.setAttribute('aria-pressed', String(liked));
      b.querySelector('.like-count').textContent = count;
      if (liked) { b.classList.remove('pop'); void b.offsetWidth; b.classList.add('pop'); }
    });
  } catch {
    alert('Most nem sikerült – próbáld újra egy kicsit később.');
  } finally {
    btn.disabled = false;
  }
});

document.querySelectorAll('[data-counter]').forEach((ta) => {
  const out = ta.form.querySelector('[data-count]');
  const update = () => { out.textContent = ta.value.length; };
  ta.addEventListener('input', update);
  update();
});

document.addEventListener('submit', (e) => {
  const msg = e.target.dataset.confirm;
  if (msg && !confirm(msg)) e.preventDefault();
});
