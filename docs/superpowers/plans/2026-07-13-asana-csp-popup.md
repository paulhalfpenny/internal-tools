# Asana CSP-Safe Popup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make time logging work when Asana's `frame-src` policy blocks `internal.filter.agency`.

**Architecture:** Return to the approved extension design: the toolbar button opens Internal Tools as a top-level popup instead of inserting a cross-origin iframe into Asana. The existing `/timesheet?log_asana={gid}` deep link supplies the prefilled form, while the content script keeps task detection and timer-status polling unchanged.

**Tech Stack:** Manifest V3, vanilla JavaScript, Node test runner, Laravel/Livewire deep link.

## Global Constraints

- Preserve project-list, My Tasks, Inbox, direct URL, timer-status, and dark-mode behavior.
- Do not weaken or rewrite Asana's Content Security Policy.
- Do not commit, push, publish, or deploy without explicit user instruction.

---

### Task 1: Popup Regression Coverage

**Files:**
- Modify: `tests/Node/asana-extension-content.test.mjs`

**Interfaces:**
- Consumes: the existing toolbar click and task-gid detection behavior.
- Produces: assertions for `window.open(url, name, features)` and the absence of a dialog iframe.

- [x] **Step 1: Write the failing test**

Record calls to `window.open()` in the VM harness and update the Inbox/direct-task assertions to require:

```js
assert.deepEqual(openedWindows[0], {
    url: `https://internal.filter.agency/timesheet?log_asana=${taskGid}`,
    name: 'filter-internal-tools-log-time',
    features: 'popup=yes,width=520,height=680,resizable=yes,scrollbars=yes',
});
assert.equal(document.lastFrame, undefined);
```

- [x] **Step 2: Run test to verify it fails**

Run: `node --test tests/Node/asana-extension-content.test.mjs`

Expected: FAIL because the current content script creates `/asana-app/tasks/{gid}` in an iframe and never calls `window.open()`.

### Task 2: Replace The Blocked Iframe

**Files:**
- Modify: `asana-extension/content.js`
- Modify: `asana-extension/manifest.json`

**Interfaces:**
- Consumes: `currentTaskGid()` and `refreshTimerStatus()`.
- Produces: `openPopup(gid)` using the tested URL, window name, and feature string.

- [x] **Step 1: Implement the minimal popup path**

Replace the dialog construction and `postMessage` handling with:

```js
function openPopup(gid) {
  const popup = window.open(
    BASE_URL + '/timesheet?log_asana=' + encodeURIComponent(gid),
    'filter-internal-tools-log-time',
    'popup=yes,width=520,height=680,resizable=yes,scrollbars=yes'
  );
  if (popup) popup.focus();
}
```

Call `openPopup()` from the existing button click handler. Remove the dialog-only IDs, styles, iframe creation, and message listener. Bump the manifest from `1.1.2` to `1.1.3`.

- [x] **Step 2: Run the focused tests**

Run: `node --test tests/Node/asana-extension-content.test.mjs`

Expected: all content-script tests PASS.

### Task 3: Documentation And Verification

**Files:**
- Modify: `asana-extension/README.md`

**Interfaces:**
- Consumes: the verified popup behavior.
- Produces: accurate installation, security, and behavior notes.

- [x] **Step 1: Update documentation**

Describe the top-level popup and `/timesheet?log_asana={gid}` deep link. Remove statements that the extension depends on iframe cookies or `frame-ancestors` permission.

- [x] **Step 2: Run full verification**

Run:

```sh
node --test tests/Node/*.test.mjs
node --check asana-extension/content.js
node --check asana-extension/background.js
npm run build
git diff --check
```

Expected: all commands exit 0. Then reload the unpacked extension and verify the toolbar click opens the authenticated Internal Tools popup from a project task and Inbox without a CSP console error.
