// Filter Internal Tools — "Log time" button for Asana tasks.
//
// Injects a button into the task-details toolbar. Clicking it opens the
// Internal Tools timesheet in a popup with the entry modal prefilled for
// the current task (/timesheet?log_asana={gid} — see DayView's deep link).
//
// Asana is a SPA whose DOM changes without warning; everything here is
// defensive. If injection fails, the extension does nothing visible and
// users fall back to logging in Internal Tools directly.

(function () {
  'use strict';

  const BASE_URL = 'https://internal.filter.agency';
  const BUTTON_ID = 'filter-log-time-button';

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
      const gid = currentTaskGid() || taskGid;
      if (!gid) return;

      const url = BASE_URL + '/timesheet?log_asana=' + encodeURIComponent(gid);
      window.open(
        url,
        'filter-log-time',
        'width=560,height=760,menubar=no,toolbar=no,location=no,status=no'
      );
    });

    return button;
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
