// Filter Internal Tools — background service worker.
//
// Proxies the running-timer status check for the content script: content
// scripts can't reliably fetch cross-origin (the page's CSP applies), but
// the service worker can, thanks to the internal.filter.agency host
// permission. Cookies ride along (the app's session cookie is
// SameSite=None), so the check is for whoever is logged in to Internal
// Tools in this browser.

const BASE_URL = 'https://internal.filter.agency';

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  if (message && message.type === 'timer-status') {
    fetch(BASE_URL + '/asana-app/timer-status', { credentials: 'include' })
      .then((response) => (response.ok ? response.json() : { running: false }))
      .then((data) => sendResponse(data))
      .catch(() => sendResponse({ running: false }));

    return true; // keep the message channel open for the async response
  }
});
