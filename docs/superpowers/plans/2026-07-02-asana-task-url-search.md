# Asana Task URL Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let users paste an Asana task URL into the day/week Asana task picker search box and match the cached task by its Asana GID.

**Architecture:** Keep the behavior in the existing shared frontend filter (`resources/js/asana-task-filter.js`) so day view and week view pick it up together. Parse likely Asana task GIDs from pasted Asana URLs before falling back to the existing normalized text search across task name, board name, and cached custom-field search text.

**Tech Stack:** Laravel 11, Livewire 4, Alpine in Blade views, Vite ES modules, Node `node:test`, Pest/PHPUnit.

## Global Constraints

- Do not add a database column or migration; `asana_tasks.gid` already stores the target Asana task ID.
- Preserve existing search behavior: typed names, board names, compact punctuation-insensitive matches, and cached `search_text` matches must still work.
- Preserve project prefilter behavior when the Asana search query is empty.
- Support both day view and week view through the shared `window.asanaTaskFilter.filterAsanaTasksForProject` helper.
- Do not match random long numbers in non-Asana prose unless the entire query is a bare numeric GID.
- Current worktree has unrelated unstaged ticket changes; do not revert them or mix them into commits for this feature.

---

## File Structure

- Modify `resources/js/asana-task-filter.js`
  - Add an exported URL/GID extraction helper.
  - Update `asanaTaskMatchesText()` and `filterAsanaTasksForProject()` to pass the original query through URL parsing.
- Modify `tests/Node/asana-task-filter.test.mjs`
  - Add unit coverage for classic Asana URLs, current project/task URLs, bare GIDs, and non-Asana numeric noise.
- Optionally modify `tests/Feature/Timesheet/TaskPickerDropdownTest.php`
  - Add a small assertion that both day/week picker scripts still delegate Asana task filtering to the shared helper.
- No Blade or PHP component changes are expected unless implementation discovers the helper is not available in a built asset.

---

### Task 1: Parse Asana Task URLs In The Shared Filter

**Files:**
- Modify: `resources/js/asana-task-filter.js`
- Test: `tests/Node/asana-task-filter.test.mjs`

**Interfaces:**
- Consumes: existing task objects shaped like `{ gid, name, board_name, search_text }`.
- Produces: exported `extractAsanaTaskGids(value): string[]`.
- Produces: existing `filterAsanaTasksForProject(tasks, projectTerms, query)` continues returning filtered task objects.

- [ ] **Step 1: Add failing Node tests for URL and GID matching**

In `tests/Node/asana-task-filter.test.mjs`, update the import and append these tests:

```js
import {
    extractAsanaTaskGids,
    filterAsanaTasksForProject,
    normalizeAsanaTaskText,
} from '../../resources/js/asana-task-filter.js';

test('extracts task gids from supported Asana task URLs', () => {
    assert.deepEqual(
        extractAsanaTaskGids('https://app.asana.com/1/155579732034488/project/1204439707387883/task/1216205972827127'),
        ['1216205972827127'],
    );

    assert.deepEqual(
        extractAsanaTaskGids('https://app.asana.com/0/1204439707387883/1216205972827127/f'),
        ['1216205972827127'],
    );
});

test('typing an Asana task URL matches the cached task gid', () => {
    const tasks = [
        { gid: '1216205972827127', name: 'Feature: Search Asana task by URL', board_name: 'Internal Tools' },
        { gid: '1216205885155910', name: 'Feature: Remember previous task', board_name: 'Internal Tools' },
    ];

    assert.deepEqual(
        filterAsanaTasksForProject(tasks, [], 'https://app.asana.com/1/155579732034488/project/1204439707387883/task/1216205972827127').map((task) => task.gid),
        ['1216205972827127'],
    );
});

test('typing a bare Asana gid matches the cached task gid', () => {
    const tasks = [
        { gid: '1216205972827127', name: 'Feature: Search Asana task by URL', board_name: 'Internal Tools' },
    ];

    assert.deepEqual(
        filterAsanaTasksForProject(tasks, [], '1216205972827127').map((task) => task.gid),
        ['1216205972827127'],
    );
});

test('does not treat non-Asana URLs as task gid searches', () => {
    const tasks = [
        { gid: '1216205972827127', name: 'Feature: Search Asana task by URL', board_name: 'Internal Tools' },
    ];

    assert.deepEqual(
        filterAsanaTasksForProject(tasks, [], 'https://example.com/tasks/1216205972827127').map((task) => task.gid),
        [],
    );
});
```

- [ ] **Step 2: Run the Node test to verify it fails**

Run:

```bash
node --test tests/Node/asana-task-filter.test.mjs
```

Expected: FAIL because `extractAsanaTaskGids` is not exported and URL search does not match task `gid`.

- [ ] **Step 3: Implement URL/GID extraction and direct GID matching**

In `resources/js/asana-task-filter.js`, add the helper near the top of the file:

```js
const ASANA_HOST_PATTERN = /(^|\.)asana\.com$/;
const ASANA_GID_PATTERN = /^\d{10,}$/;

function addUniqueGid(gids, value) {
    const gid = String(value ?? '').trim();

    if (ASANA_GID_PATTERN.test(gid) && !gids.includes(gid)) {
        gids.push(gid);
    }
}

export function extractAsanaTaskGids(value) {
    const raw = String(value ?? '').trim();
    const gids = [];

    if (ASANA_GID_PATTERN.test(raw)) {
        addUniqueGid(gids, raw);
    }

    const urls = raw.match(/https?:\/\/[^\s<>"']+/g) ?? [];

    for (const urlText of urls) {
        let url;

        try {
            url = new URL(urlText);
        } catch {
            continue;
        }

        if (!ASANA_HOST_PATTERN.test(url.hostname)) {
            continue;
        }

        const segments = url.pathname.split('/').filter(Boolean);
        const taskSegmentIndex = segments.indexOf('task');

        if (taskSegmentIndex !== -1) {
            addUniqueGid(gids, segments[taskSegmentIndex + 1]);
        }

        if (segments[0] === '0') {
            addUniqueGid(gids, segments[2]);
        }
    }

    return gids;
}
```

Then update `asanaTaskMatchesText()`:

```js
export function asanaTaskMatchesText(task, text) {
    const taskGid = String(task?.gid ?? '');

    if (taskGid !== '' && extractAsanaTaskGids(text).includes(taskGid)) {
        return true;
    }

    const normalizedText = normalizeAsanaTaskText(text);

    if (normalizedText === '') {
        return false;
    }

    const haystack = taskText(task);
    const normalizedHaystack = normalizeAsanaTaskText(haystack);

    return normalizedHaystack.includes(normalizedText)
        || compactAsanaTaskText(haystack).includes(compactAsanaTaskText(normalizedText));
}
```

Then update the non-empty query branch in `filterAsanaTasksForProject()` so it sends the original query to the matcher:

```js
export function filterAsanaTasksForProject(tasks, projectTerms = [], query = '') {
    const taskList = Array.isArray(tasks) ? tasks : [];
    const normalizedQuery = normalizeAsanaTaskText(query);
    const queryGids = extractAsanaTaskGids(query);

    if (normalizedQuery !== '' || queryGids.length > 0) {
        return taskList.filter((task) => asanaTaskMatchesText(task, query));
    }

    const terms = Array.isArray(projectTerms)
        ? projectTerms.filter((term) => normalizeAsanaTaskText(term) !== '')
        : [];

    if (terms.length === 0) {
        return taskList;
    }

    return taskList.filter((task) => terms.some((term) => asanaTaskMatchesText(task, term)));
}
```

- [ ] **Step 4: Run the Node test to verify it passes**

Run:

```bash
node --test tests/Node/asana-task-filter.test.mjs
```

Expected: PASS with all existing and new `asana-task-filter` tests passing.

- [ ] **Step 5: Commit**

Only if commits are requested for this work:

```bash
git add resources/js/asana-task-filter.js tests/Node/asana-task-filter.test.mjs
git commit -m "Support Asana task URL search"
```

---

### Task 2: Verify Day And Week Picker Integration

**Files:**
- Modify: `tests/Feature/Timesheet/TaskPickerDropdownTest.php`
- Test: `tests/Feature/Timesheet/TaskPickerDropdownTest.php`

**Interfaces:**
- Consumes: `window.asanaTaskFilter.filterAsanaTasksForProject` exposed from `resources/js/app.js`.
- Produces: PHP feature coverage that the day/week picker scripts continue using the shared helper.

- [ ] **Step 1: Add failing-or-protective feature assertions for shared helper usage**

In `tests/Feature/Timesheet/TaskPickerDropdownTest.php`, update `assertPickersSearchFromMainInputs()` to include this assertion:

```php
expect($html)
    ->toContain('window.asanaTaskFilter?.filterAsanaTasksForProject');
```

Keep the existing assertions in that helper.

- [ ] **Step 2: Run the feature test to verify integration coverage**

Run:

```bash
php artisan test tests/Feature/Timesheet/TaskPickerDropdownTest.php --filter="dropdowns search from their main inputs"
```

Expected: PASS for both day view and week view dropdown integration tests.

- [ ] **Step 3: Build assets to verify the browser bundle accepts the helper**

Run:

```bash
npm run build
```

Expected: Vite build exits 0 and emits the production assets.

- [ ] **Step 4: Run the focused regression set**

Run:

```bash
node --test tests/Node/asana-task-filter.test.mjs
php artisan test tests/Feature/Timesheet/TaskPickerDropdownTest.php tests/Feature/Asana/DayViewAsanaTaskRequiredTest.php tests/Feature/Timesheet/WeekViewTest.php
```

Expected: all listed tests pass.

- [ ] **Step 5: Commit**

Only if commits are requested for this work:

```bash
git add tests/Feature/Timesheet/TaskPickerDropdownTest.php
git commit -m "Verify Asana task picker URL search integration"
```

---

## Self-Review

- Spec coverage: The task asks for pasted Asana links to locate tasks. Task 1 covers URL parsing and direct cached `gid` matching; Task 2 confirms both day/week pickers use the shared filter.
- Placeholder scan: No placeholders remain; each code step includes concrete code and commands.
- Type consistency: `extractAsanaTaskGids(value): string[]` is exported in Task 1 and imported by Node tests in the same task. `filterAsanaTasksForProject()` signature stays unchanged for existing callers.

