// Filter Internal Tools — "Log time" button for Asana tasks.
//
// Injects a button into the task-details toolbar. Clicking it opens a
// native <dialog> (Harvest-style) hosting the compact log-time form for
// the current task (/asana-app/tasks/{gid}) in an iframe. The embed page
// drives the dialog over postMessage: it reports its content height,
// asks to close on Cancel, and announces saves so the dialog dismisses
// itself. Requires the app to send SameSite=None session cookies and a
// frame-ancestors policy allowing app.asana.com.
//
// Asana is a SPA whose DOM changes without warning; everything here is
// defensive. If injection fails, the extension does nothing visible and
// users fall back to logging in Internal Tools directly.

(function () {
  'use strict';

  const BASE_URL = 'https://internal.filter.agency';
  const BUTTON_ID = 'filter-log-time-button';
  const DIALOG_ID = 'filter-log-time-dialog';
  const DOT_ID = 'filter-log-time-dot';

  const COLOR_IDLE = '#6d6e6f';   // Asana toolbar grey
  const COLOR_HOVER = '#1e1f21';
  const COLOR_RUNNING = '#16a34a'; // green-600, matches the app's timer UI

  // Latest known timer status for the logged-in Internal Tools user.
  let timerRunning = false;
  let timerGid = null;

  // ::backdrop can't be styled inline; one stylesheet for the dialog shell.
  const style = document.createElement('style');
  style.textContent =
    '#' + DIALOG_ID + '::backdrop{background:rgba(0,0,0,0.4);}' +
    '#' + DIALOG_ID + '{width:520px;max-width:calc(100vw - 32px);' +
    'height:560px;max-height:calc(100vh - 64px);padding:0;border:none;' +
    'border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,0.28);overflow:hidden;}';
  document.head.appendChild(style);

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
      'width:28px', 'height:28px', 'margin:5px 2px 0', 'padding:0',
      'border:none', 'border-radius:6px', 'background:transparent',
      'cursor:pointer', 'color:' + COLOR_IDLE, 'position:relative',
    ].join(';');
    button.addEventListener('mouseenter', () => {
      button.style.background = 'rgba(55,23,23,0.06)';
      if (!runningOnCurrentTask()) button.style.color = COLOR_HOVER;
    });
    button.addEventListener('mouseleave', () => {
      button.style.background = 'transparent';
      applyTimerVisual();
    });

    button.innerHTML =
      '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">' +
      '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2.5 2.5M9 2h6"/></svg>';

    button.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();

      if (document.getElementById(DIALOG_ID)) {
        closeDialog();
        return;
      }

      const gid = currentTaskGid() || taskGid;
      if (gid) openDialog(gid);
    });

    return button;
  }

  function runningOnCurrentTask() {
    return timerRunning && timerGid !== null && timerGid === currentTaskGid();
  }

  // Green stopwatch = timer running on this task; green corner dot = timer
  // running on another task; grey = no timer.
  function applyTimerVisual() {
    const button = document.getElementById(BUTTON_ID);
    if (!button) return;

    const onThisTask = runningOnCurrentTask();
    button.style.color = onThisTask ? COLOR_RUNNING : COLOR_IDLE;
    button.title = onThisTask
      ? 'Timer running on this task — click to view or stop'
      : (timerRunning
        ? 'Timer running on another task — Log time in Filter Internal Tools'
        : 'Log time in Filter Internal Tools');

    let dot = document.getElementById(DOT_ID);
    const wantDot = timerRunning && !onThisTask;
    if (wantDot && !dot) {
      dot = document.createElement('span');
      dot.id = DOT_ID;
      dot.style.cssText = [
        'position:absolute', 'top:2px', 'right:2px',
        'width:7px', 'height:7px', 'border-radius:9999px',
        'background:' + COLOR_RUNNING, 'pointer-events:none',
      ].join(';');
      button.appendChild(dot);
    } else if (!wantDot && dot) {
      dot.remove();
    }
  }

  // The background service worker does the actual fetch (content scripts
  // are subject to the page's CSP; the worker has the host permission).
  function refreshTimerStatus() {
    try {
      chrome.runtime.sendMessage({ type: 'timer-status' }, (data) => {
        if (chrome.runtime.lastError || !data) return;
        timerRunning = !!data.running;
        timerGid = data.gid || null;
        applyTimerVisual();
      });
    } catch (e) {
      // Extension was reloaded/orphaned; the next page load recovers.
    }
  }

  function closeDialog() {
    const dialog = document.getElementById(DIALOG_ID);
    if (dialog) dialog.close();
  }

  // Native <dialog> gives us the centered top-layer panel, dimmed backdrop,
  // Esc-to-close and focus containment for free. The embed page inside the
  // iframe reports its height so the panel hugs the form like Harvest's.
  function openDialog(gid) {
    closeDialog();

    const dialog = document.createElement('dialog');
    dialog.id = DIALOG_ID;

    const frame = document.createElement('iframe');
    frame.src = BASE_URL + '/asana-app/tasks/' + encodeURIComponent(gid);
    frame.style.cssText = 'display:block;width:100%;height:100%;border:none;';
    dialog.appendChild(frame);

    // A click on the backdrop lands on the dialog element itself.
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) dialog.close();
    });
    dialog.addEventListener('close', function () {
      dialog.remove();
    });

    document.body.appendChild(dialog);
    dialog.showModal();
  }

  // Messages from the embed page (see asana-log-time.blade.php).
  window.addEventListener('message', function (event) {
    if (event.origin !== BASE_URL) return;

    const type = typeof event.data === 'string' ? event.data : event.data && event.data.type;

    if (type === 'filter-log-time:saved') {
      // Leave the success state visible for a beat before dismissing.
      setTimeout(closeDialog, 900);
      // A save can start or stop a timer — re-check once the dust settles.
      setTimeout(refreshTimerStatus, 1200);
    } else if (type === 'filter-log-time:close') {
      closeDialog();
      setTimeout(refreshTimerStatus, 400);
    } else if (type === 'filter-log-time:height' && typeof event.data.height === 'number') {
      const dialog = document.getElementById(DIALOG_ID);
      if (dialog) {
        const height = Math.max(180, Math.min(event.data.height, window.innerHeight - 64));
        dialog.style.height = height + 'px';
      }
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
    applyTimerVisual();
  }

  // Re-evaluate the icon when the user navigates between tasks (SPA URL
  // changes don't reload the content script).
  let lastGid = null;
  setInterval(function () {
    const gid = currentTaskGid();
    if (gid !== lastGid) {
      lastGid = gid;
      applyTimerVisual();
    }
  }, 1000);

  setInterval(refreshTimerStatus, 30000);
  refreshTimerStatus();

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
