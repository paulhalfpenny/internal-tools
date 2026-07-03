// Filter Internal Tools — "Log time" button for Asana tasks.
//
// Injects a button into the task-details toolbar. Clicking it opens a
// centered in-page overlay (iframe) hosting the compact log-time form for
// the current task (/asana-app/tasks/{gid}). The app
// posts 'filter-log-time:saved' back when the entry saves so the overlay
// closes itself. Requires the app to send SameSite=None session cookies
// and a frame-ancestors policy allowing app.asana.com.
//
// Asana is a SPA whose DOM changes without warning; everything here is
// defensive. If injection fails, the extension does nothing visible and
// users fall back to logging in Internal Tools directly.

(function () {
  'use strict';

  const BASE_URL = 'https://internal.filter.agency';
  const BUTTON_ID = 'filter-log-time-button';
  const OVERLAY_ID = 'filter-log-time-overlay';

  // The current task gid, from the URL. Asana URL shapes seen in the wild:
  //   /1/{workspace}/project/{project}/task/{task}
  //   /1/{workspace}/task/{task}
  //   /0/{project}/{task}            (legacy)
  //   /0/{project}/{task}/f          (legacy, full-screen)
  //   ...?task={task}                (some inbox/search views)
  function currentTaskGid() {
    const href = window.location.href;

    let m = href.match(/\/task\/(\d{6,})/);
    if (m) return m[1];

    m = new URL(href).searchParams.get('task');
    if (m && /^\d{6,}$/.test(m)) return m;

    m = href.match(/\/0\/\d{6,}\/(\d{6,})/);
    if (m) return m[1];

    return null;
  }

  // Anchor: the "Like this task" icon in the task header's icon row (where
  // Harvest puts its stopwatch), falling back to the "Mark complete" button.
  // Both located by aria-label/text — Asana ships no stable toolbar test id.
  function findAnchor() {
    let fallback = null;

    for (const el of document.querySelectorAll('button, [role="button"]')) {
      const label = ((el.getAttribute('aria-label') || '') + ' ' + (el.textContent || ''))
        .trim().toLowerCase();
      if (label.includes('like this task')) {
        return el;
      }
      if (fallback === null && label.includes('mark complete')) {
        fallback = el;
      }
    }

    return fallback;
  }

  function buildButton(taskGid) {
    const button = document.createElement('button');
    button.id = BUTTON_ID;
    button.type = 'button';
    button.title = 'Log time in Filter Internal Tools';
    button.setAttribute('aria-label', 'Log time in Filter Internal Tools');
    // Icon-only, sized to sit in Asana's task-header icon row.
    button.style.cssText = [
      'display:inline-flex', 'align-items:center', 'justify-content:center',
      'width:28px', 'height:28px', 'margin:0 2px', 'padding:0',
      'border:none', 'border-radius:6px', 'background:transparent',
      'cursor:pointer', 'color:inherit',
    ].join(';');
    button.addEventListener('mouseenter', () => { button.style.background = 'rgba(55,23,23,0.06)'; });
    button.addEventListener('mouseleave', () => { button.style.background = 'transparent'; });

    button.innerHTML =
      '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">' +
      '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5M9 2h6"/></svg>';

    button.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();

      if (document.getElementById(OVERLAY_ID)) {
        closeOverlay();
        return;
      }

      const gid = currentTaskGid() || taskGid;
      if (gid) openOverlay(gid);
    });

    return button;
  }

  function closeOverlay() {
    const overlay = document.getElementById(OVERLAY_ID);
    if (overlay) overlay.remove();
    document.removeEventListener('keydown', onOverlayKeydown, true);
    document.removeEventListener('pointerdown', onOverlayPointerdown, true);
  }

  function onOverlayKeydown(event) {
    if (event.key === 'Escape') closeOverlay();
  }

  function onOverlayPointerdown(event) {
    if (event.target === document.getElementById(OVERLAY_ID)) closeOverlay();
  }

  // Harvest-style panel: centered over a dimmed backdrop, hosting the
  // compact log-time form in an iframe. The user's normal Internal Tools
  // session applies (cookies are SameSite=None); if they're logged out the
  // login screen renders in the panel, or they can pop out to a tab.
  function openOverlay(gid) {
    closeOverlay();

    const url = BASE_URL + '/asana-app/tasks/' + encodeURIComponent(gid);

    const backdrop = document.createElement('div');
    backdrop.id = OVERLAY_ID;
    backdrop.style.cssText = [
      'position:fixed', 'inset:0', 'z-index:2147483000',
      'display:flex', 'align-items:center', 'justify-content:center',
      'background:rgba(0,0,0,0.4)',
    ].join(';');

    const overlay = document.createElement('div');
    overlay.style.cssText = [
      'width:520px', 'max-width:calc(100vw - 32px)',
      'height:560px', 'max-height:calc(100vh - 64px)',
      'display:flex', 'flex-direction:column', 'overflow:hidden',
      'background:#fff', 'border-radius:12px',
      'box-shadow:0 12px 40px rgba(0,0,0,0.28)',
    ].join(';');

    const header = document.createElement('div');
    header.style.cssText = [
      'display:flex', 'align-items:center', 'justify-content:space-between',
      'padding:10px 14px', 'border-bottom:1px solid #e8e8e8',
      'font:600 13px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
      'color:#1e1f21', 'flex:none',
    ].join(';');

    const title = document.createElement('span');
    title.textContent = 'Log time — Filter Internal Tools';

    const actions = document.createElement('span');
    actions.style.cssText = 'display:inline-flex;align-items:center;gap:12px;';

    const popOut = document.createElement('a');
    popOut.href = url;
    popOut.target = '_blank';
    popOut.rel = 'noopener';
    popOut.textContent = 'Open in tab ↗';
    popOut.style.cssText = 'font-weight:400;font-size:12px;color:#6d6e6f;text-decoration:none;';
    popOut.addEventListener('click', () => closeOverlay());

    const close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Close');
    close.textContent = '✕';
    close.style.cssText = [
      'border:none', 'background:transparent', 'cursor:pointer',
      'font-size:14px', 'color:#6d6e6f', 'padding:2px 4px', 'line-height:1',
    ].join(';');
    close.addEventListener('click', () => closeOverlay());

    actions.append(popOut, close);
    header.append(title, actions);

    const frame = document.createElement('iframe');
    frame.src = url;
    frame.style.cssText = 'flex:1;width:100%;border:none;';

    overlay.append(header, frame);
    backdrop.appendChild(overlay);
    document.body.appendChild(backdrop);

    document.addEventListener('keydown', onOverlayKeydown, true);
    document.addEventListener('pointerdown', onOverlayPointerdown, true);
  }

  // The app posts this after a successful save (see day-view.blade.php).
  window.addEventListener('message', function (event) {
    if (event.origin === BASE_URL && event.data === 'filter-log-time:saved') {
      setTimeout(closeOverlay, 600);
    }
  });

  function inject() {
    const gid = currentTaskGid();
    const existing = document.getElementById(BUTTON_ID);

    if (!gid) {
      if (existing) existing.remove();
      return;
    }

    if (existing) {
      // If a re-render detached or hid it, re-inject fresh.
      const rect = existing.getBoundingClientRect();
      if (document.contains(existing) && rect.width > 0 && rect.height > 0) {
        return;
      }
      existing.remove();
    }

    const anchor = findAnchor();
    if (!anchor) return;

    anchor.insertAdjacentElement('afterend', buildButton(gid));
  }

  // Debounced observer: Asana re-renders constantly; check at most every
  // 500ms after mutations settle.
  let timer = null;
  const observer = new MutationObserver(function () {
    if (timer) clearTimeout(timer);
    timer = setTimeout(inject, 500);
  });

  observer.observe(document.body, { childList: true, subtree: true });
  inject();
})();
