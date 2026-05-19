(function () {
  function b64ToUtf8(b64) {
    try {
      const bin = atob(b64);
      const bytes = Uint8Array.from(bin, c => c.charCodeAt(0));
      if (window.TextDecoder) return new TextDecoder('utf-8').decode(bytes);
      let s = '';
      bytes.forEach(b => s += String.fromCharCode(b));
      return decodeURIComponent(escape(s));
    } catch (e) { return ''; }
  }

  function safeJsonParse(str) {
    try { return JSON.parse(str); } catch (e) { return null; }
  }

  // Progress animation
  function animateProgress() {
    const fills = document.querySelectorAll('.cptt-progressbar__fill[data-cptt-width]');
    if (!fills.length) return;

    const run = (el) => {
      const w = parseInt(el.getAttribute('data-cptt-width') || '0', 10);
      el.style.width = '0%';
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          el.style.width = Math.max(0, Math.min(100, w)) + '%';
        });
      });
    };

    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver((entries) => {
        entries.forEach(en => {
          if (en.isIntersecting) {
            run(en.target);
            io.unobserve(en.target);
          }
        });
      }, { threshold: 0.25 });
      fills.forEach(f => io.observe(f));
    } else {
      fills.forEach(run);
    }
  }

  /**
   * Desktop: grey full line + green overlay until last DONE only
   * Mobile: keep previous gradient logic via --cptt-vfill (done-only)
   */
  function syncStepperLines() {
    const steppers = document.querySelectorAll('ol.cptt-stepper');
    if (!steppers.length) return;

    steppers.forEach(ol => {
      const lis = ol.querySelectorAll('li.cptt-step');
      const icons = ol.querySelectorAll('.cptt-tl__icon');

      if (!lis.length || !icons.length || icons.length !== lis.length) return;
      if (icons.length < 2) return;

      // last DONE index (NOT current)
      let lastDoneIdx = -1;
      lis.forEach((li, i) => {
        if (li.classList.contains('cptt-step--done')) lastDoneIdx = i;
      });

      const rOl = ol.getBoundingClientRect();

      const firstCenter = {
        x: ((icons[0].getBoundingClientRect().left + icons[0].getBoundingClientRect().right) / 2) - rOl.left,
        y: ((icons[0].getBoundingClientRect().top + icons[0].getBoundingClientRect().bottom) / 2) - rOl.top
      };
      const lastCenter = {
        x: ((icons[icons.length - 1].getBoundingClientRect().left + icons[icons.length - 1].getBoundingClientRect().right) / 2) - rOl.left,
        y: ((icons[icons.length - 1].getBoundingClientRect().top + icons[icons.length - 1].getBoundingClientRect().bottom) / 2) - rOl.top
      };

      // if no done -> green fill 0
      let progCenter = null;
      if (lastDoneIdx >= 0) {
        const r = icons[lastDoneIdx].getBoundingClientRect();
        progCenter = {
          x: ((r.left + r.right) / 2) - rOl.left,
          y: ((r.top + r.bottom) / 2) - rOl.top
        };
      }

      // endpoints for grey line / vertical line
      const topY = Math.min(firstCenter.y, lastCenter.y);
      const botY = Math.max(firstCenter.y, lastCenter.y);
      const leftX = Math.min(firstCenter.x, lastCenter.x);
      const rightX = Math.max(firstCenter.x, lastCenter.x);

      // mobile vars
      ol.style.setProperty('--cptt-vx', firstCenter.x + 'px');
      ol.style.setProperty('--cptt-vtop', topY + 'px');
      ol.style.setProperty('--cptt-vbottom', (rOl.height - botY) + 'px');

      // desktop vars (used by CSS for endpoints)
      ol.style.setProperty('--cptt-hl', leftX + 'px');
      ol.style.setProperty('--cptt-hr', (rOl.width - rightX) + 'px');
      ol.style.setProperty('--cptt-hy', firstCenter.y + 'px');

      // mobile green fill percentage (done-only)
      let vFill = 0;
      if (progCenter) {
        const denom = Math.max(1, (botY - topY));
        vFill = (Math.abs(progCenter.y - topY) / denom) * 100;
      }
      ol.style.setProperty('--cptt-vfill', Math.max(0, Math.min(100, vFill)).toFixed(2) + '%');

      // desktop green overlay vars
      // width starts from first icon center -> extends to last DONE icon center
      // Determine direction by visual positions:
      // if lastCenter.x > firstCenter.x => progression is left->right visually.
      // else right->left.
      let greenLeft = 0;
      let greenWidth = 0;

      if (progCenter) {
        const startX = firstCenter.x;
        const endX = lastCenter.x;

        if (endX > startX) {
          greenLeft = startX;
          greenWidth = Math.max(0, progCenter.x - startX);
        } else {
          greenLeft = progCenter.x;
          greenWidth = Math.max(0, startX - progCenter.x);
        }
        // clamp to line length
        const lineLen = Math.abs(endX - startX);
        greenWidth = Math.min(greenWidth, lineLen);
      } else {
        // no done
        greenLeft = leftX;
        greenWidth = 0;
      }

      ol.style.setProperty('--cptt-hgreenLeft', greenLeft + 'px');
      ol.style.setProperty('--cptt-hgreenWidth', greenWidth + 'px');
    });
  }

  // Modal elements
  const modal = document.querySelector('.cptt-modal');
  const titleEl = modal ? modal.querySelector('#cptt-modal-title') : null;
  const metaEl = modal ? modal.querySelector('#cptt-modal-meta') : null;
  const contentEl = modal ? modal.querySelector('#cptt-modal-content') : null;
  const checklistEl = modal ? modal.querySelector('#cptt-modal-checklist') : null;

  let lastFocus = null;

  function renderChecklist(data) {
    if (!checklistEl) return;
    checklistEl.innerHTML = '';

    if (!data || !Array.isArray(data.items) || data.items.length === 0) return;

    const total = typeof data.total === 'number' ? data.total : data.items.length;
    const done = typeof data.done === 'number'
      ? data.done
      : data.items.filter(x => x && x.done).length;

    const head = document.createElement('div');
    head.className = 'cptt-cl-head';
    head.textContent = 'چک‌لیست (' + done + '/' + total + ')';
    checklistEl.appendChild(head);

    const ul = document.createElement('ul');
    ul.className = 'cptt-cl';

    data.items.forEach(it => {
  if (!it || !it.text) return;

  const li = document.createElement('li');
  li.className = it.done ? 'is-done' : '';

  const text = document.createElement('span');
  text.className = 'cptt-cl-text';
  text.textContent = it.text;
  li.appendChild(text);

  // time + who
  if (it.done && (it.done_at_fa || it.done_by_name)) {
    const tm = document.createElement('span');
    tm.className = 'cptt-cl-time';

    const parts = [];
    if (it.done_at_fa) parts.push(it.done_at_fa);
    if (it.done_by_name) parts.push('توسط: ' + it.done_by_name);

    tm.textContent = parts.join(' — ');
    li.appendChild(tm);
  }

  // result link (only if done)
  if (it.done && it.url) {
    const url = String(it.url || '').trim();
    if (url.startsWith('http://') || url.startsWith('https://')) {
      const a = document.createElement('a');
      a.className = 'cptt-cl-link';
      a.href = url;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.textContent = 'مشاهده نتیجه';
      li.appendChild(a);
    }
  }

  ul.appendChild(li);
});

    checklistEl.appendChild(ul);
  }

  function openModal(title, updatedFa, htmlDesc, checklistData) {
    if (!modal) return;
    lastFocus = document.activeElement;

    if (titleEl) titleEl.textContent = title || '';
    if (metaEl) metaEl.textContent = updatedFa ? ('آخرین تغییر وضعیت: ' + updatedFa) : '';
    if (contentEl) {
      contentEl.innerHTML = htmlDesc && htmlDesc.trim()
        ? htmlDesc
        : '<div style="color:#6b7280">توضیحی برای این مرحله ثبت نشده است.</div>';
    }

    renderChecklist(checklistData);

    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');

    const closeBtn = modal.querySelector('.cptt-modal__close');
    if (closeBtn) closeBtn.focus();
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    if (lastFocus) lastFocus.focus();
  }

  document.addEventListener('click', function (e) {
    const btn = e.target.closest('.cptt-step__btn');
    if (btn) {
      const t = btn.getAttribute('data-cptt-step-title') || '';
      const updated = btn.getAttribute('data-cptt-step-updated') || '';

      const b64desc = btn.getAttribute('data-cptt-step-desc-b64') || '';
      const html = b64desc ? b64ToUtf8(b64desc) : '';

      const b64cl = btn.getAttribute('data-cptt-checklist-b64') || '';
      const clJson = b64cl ? b64ToUtf8(b64cl) : '';
      const clData = clJson ? safeJsonParse(clJson) : null;

      openModal(t, updated, html, clData);
      return;
    }

    if (e.target.closest('[data-cptt-close]')) closeModal();
  });

  document.addEventListener('keydown', function (e) {
    if (!modal || !modal.classList.contains('is-open')) return;
    if (e.key === 'Escape') closeModal();
  });

  function initAll() {
    animateProgress();

    requestAnimationFrame(() => {
      syncStepperLines();
      setTimeout(syncStepperLines, 120);
      setTimeout(syncStepperLines, 400);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  let t = null;
  window.addEventListener('resize', function () {
    clearTimeout(t);
    t = setTimeout(syncStepperLines, 150);
  }, { passive: true });
})();