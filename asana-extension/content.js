// Filter Internal Tools — "Log time" button for Asana tasks.
//
// Injects a button into the task-details toolbar. Clicking it opens the
// timesheet's prefilled Asana entry form in a small popup window. A top-level
// popup is required because Asana's frame-src policy blocks third-party
// iframes that are not on its allowlist.
//
// Asana is a SPA whose DOM changes without warning; everything here is
// defensive. If injection fails, the extension does nothing visible and
// users fall back to logging in Internal Tools directly.

(function () {
  'use strict';

  const BASE_URL = 'https://internal.filter.agency';
  const BUTTON_ID = 'filter-log-time-button';
  const DOT_ID = 'filter-log-time-dot';

  const COLOR_RUNNING = '#16a34a'; // green-600, matches the app's timer UI

  // Latest known timer status for the logged-in Internal Tools user.
  let timerRunning = false;
  let timerGid = null;

  // The visible task pane is the most reliable task identity across project
  // lists, My Tasks and Inbox. Inbox URLs identify both a task and a story,
  // while the pane consistently exposes the task as data-task-id.
  function currentTaskPane() {
    for (const pane of document.querySelectorAll('[role="dialog"][data-task-id]')) {
      const gid = pane.getAttribute('data-task-id');
      const rect = pane.getBoundingClientRect();
      if (/^\d{6,}$/.test(gid || '') && rect.width > 0 && rect.height > 0) {
        return pane;
      }
    }

    return null;
  }

  // The current task gid, preferring the visible pane and falling back to the
  // URL. Asana URL shapes seen in the wild:
  //   /1/{workspace}/project/{project}/task/{task}
  //   /1/{workspace}/task/{task}
  //   /0/{project}/{task}            (legacy)
  //   /0/{project}/{task}/f          (legacy, full-screen)
  //   ...?task={task}                (some inbox/search views)
  function currentTaskGid() {
    const pane = currentTaskPane();
    if (pane) return pane.getAttribute('data-task-id');

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
    const root = currentTaskPane() || document;

    for (const el of root.querySelectorAll('button, [role="button"]')) {
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
      'cursor:pointer', 'color:inherit', 'position:relative',
    ].join(';');
    button.addEventListener('mouseenter', () => {
      button.style.background = 'rgba(127,127,127,0.15)';
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

      const gid = currentTaskGid() || taskGid;
      if (gid) openPopup(gid);
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
    button.style.color = onThisTask ? COLOR_RUNNING : 'inherit';
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

  function openPopup(gid) {
    const popup = window.open(
      BASE_URL + '/timesheet?log_asana=' + encodeURIComponent(gid),
      'filter-internal-tools-log-time',
      'popup=yes,width=520,height=680,resizable=yes,scrollbars=yes'
    );
    if (popup) popup.focus();
  }

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
