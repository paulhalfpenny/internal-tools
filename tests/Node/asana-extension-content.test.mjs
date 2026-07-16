import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const contentScript = readFileSync(
    new URL('../../asana-extension/content.js', import.meta.url),
    'utf8',
);

class FakeElement {
    constructor(document, tagName, attributes = {}) {
        this.document = document;
        this.tagName = tagName.toUpperCase();
        this.attributes = new Map(Object.entries(attributes));
        this.children = [];
        this.listeners = new Map();
        this.style = {};
        this.textContent = '';
    }

    addEventListener(type, listener) {
        this.listeners.set(type, listener);
    }

    appendChild(child) {
        this.children.push(child);
        child.parentElement = this;
        this.document.register(child);

        if (child.tagName === 'IFRAME') {
            this.document.lastFrame = child;
        }
    }

    click() {
        this.listeners.get('click')?.({
            preventDefault() {},
            stopPropagation() {},
            target: this,
        });
    }

    close() {
        this.listeners.get('close')?.({ target: this });
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    getBoundingClientRect() {
        return { width: 100, height: 28 };
    }

    insertAdjacentElement(position, element) {
        assert.equal(position, 'afterend');
        this.document.insertionAnchor = this;
        this.document.register(element);
    }

    querySelectorAll(selector) {
        if (selector === 'button, [role="button"]') {
            return this.toolbarButtons ?? [];
        }

        return [];
    }

    remove() {
        if (this.id) {
            this.document.elementsById.delete(this.id);
        }
    }

    setAttribute(name, value) {
        this.attributes.set(name, value);
    }

    showModal() {
        this.open = true;
    }
}

function runContentScript({ href, paneGid, withOutsideAnchor = false }) {
    let activePaneGid = paneGid;
    let observerCallback;
    const pendingTimeouts = [];
    const openedWindows = [];
    const document = {
        elementsById: new Map(),
        register(element) {
            if (element.id) {
                this.elementsById.set(element.id, element);
            }
        },
    };

    const pane = new FakeElement(document, 'div', {
        role: 'dialog',
        'data-task-id': paneGid,
    });
    const paneAnchor = new FakeElement(document, 'div', {
        'aria-label': '0 likes. Click to like this task',
        role: 'button',
    });
    const outsideAnchor = new FakeElement(document, 'div', {
        'aria-label': '0 likes. Click to like this task',
        role: 'button',
    });
    pane.toolbarButtons = [paneAnchor];

    document.body = new FakeElement(document, 'body');
    document.head = new FakeElement(document, 'head');
    document.contains = (element) => document.elementsById.get(element.id) === element;
    document.createElement = (tagName) => new FakeElement(document, tagName);
    document.getElementById = (id) => document.elementsById.get(id) ?? null;
    document.querySelectorAll = (selector) => {
        if (selector === 'button, [role="button"]') {
            return withOutsideAnchor ? [outsideAnchor, paneAnchor] : [paneAnchor];
        }
        if (selector.includes('[data-task-id]')) {
            return activePaneGid ? [pane] : [];
        }

        return [];
    };

    const window = {
        addEventListener() {},
        innerHeight: 900,
        location: { href },
        open(url, name, features) {
            openedWindows.push({ url, name, features });

            return { focus() {} };
        },
    };

    vm.runInNewContext(contentScript, {
        URL,
        chrome: {
            runtime: {
                lastError: null,
                sendMessage(message, callback) {
                    assert.deepEqual(message, { type: 'timer-status' });
                    callback({ running: false });
                },
            },
        },
        document,
        MutationObserver: class {
            constructor(callback) {
                observerCallback = callback;
            }

            observe() {}
        },
        clearTimeout(id) {
            pendingTimeouts[id - 1] = null;
        },
        setInterval() {},
        setTimeout(callback) {
            pendingTimeouts.push(callback);
            return pendingTimeouts.length;
        },
        window,
    });

    return {
        document,
        openedWindows,
        outsideAnchor,
        paneAnchor,
        updateTask({ gid, nextHref }) {
            activePaneGid = gid;
            window.location.href = nextHref;
            if (gid) {
                pane.setAttribute('data-task-id', gid);
            } else {
                pane.attributes.delete('data-task-id');
            }

            observerCallback();

            for (const callback of pendingTimeouts.splice(0)) {
                callback?.();
            }
        },
    };
}

test('opens an Internal Tools popup for an Inbox task without a CSP-blocked iframe', () => {
    const taskGid = '1216506014622816';
    const { document, openedWindows, paneAnchor } = runContentScript({
        href: `https://app.asana.com/1/155579732034488/inbox/1204439707387879/item/${taskGid}/story/1216508340008049`,
        paneGid: taskGid,
    });

    const button = document.getElementById('filter-log-time-button');

    assert.ok(button);
    assert.equal(document.insertionAnchor, paneAnchor);

    button.click();

    assert.deepEqual(openedWindows[0], {
        url: `https://internal.filter.agency/timesheet?log_asana=${taskGid}`,
        name: 'filter-internal-tools-log-time',
        features: 'popup=yes,width=520,height=680,resizable=yes,scrollbars=yes',
    });
    assert.equal(document.lastFrame, undefined);
});

test('injects beside the visible task pane toolbar instead of another task control', () => {
    const taskGid = '1216506014622816';
    const { document, paneAnchor } = runContentScript({
        href: `https://app.asana.com/1/155579732034488/project/1204439707387883/task/${taskGid}`,
        paneGid: taskGid,
        withOutsideAnchor: true,
    });

    assert.equal(document.insertionAnchor, paneAnchor);
});

test('keeps the timer icon visible when hovering in dark mode', () => {
    const taskGid = '1216506014622816';
    const { document } = runContentScript({
        href: `https://app.asana.com/1/155579732034488/project/1204439707387883/task/${taskGid}`,
        paneGid: taskGid,
    });
    const button = document.getElementById('filter-log-time-button');

    button.listeners.get('mouseenter')();

    assert.equal(button.style.color, 'inherit');
});

test('keeps direct task URLs working when no task pane is available yet', () => {
    const taskGid = '1216506014622816';
    const { document, openedWindows } = runContentScript({
        href: `https://app.asana.com/1/155579732034488/project/1204439707387883/task/${taskGid}`,
        paneGid: null,
    });

    const button = document.getElementById('filter-log-time-button');

    assert.ok(button);

    button.click();

    assert.equal(
        openedWindows[0].url,
        `https://internal.filter.agency/timesheet?log_asana=${taskGid}`,
    );
});

test('tracks Inbox task navigation and removes the timer when the pane closes', () => {
    const firstTaskGid = '1216506014622816';
    const secondTaskGid = '1216153275867416';
    const harness = runContentScript({
        href: `https://app.asana.com/1/155579732034488/inbox/1204439707387879/item/${firstTaskGid}/story/1216508340008049`,
        paneGid: firstTaskGid,
    });

    harness.updateTask({
        gid: secondTaskGid,
        nextHref: `https://app.asana.com/1/155579732034488/inbox/1204439707387879/item/${secondTaskGid}/story/1216508340008050`,
    });

    harness.document.getElementById('filter-log-time-button').click();

    assert.equal(
        harness.openedWindows[0].url,
        `https://internal.filter.agency/timesheet?log_asana=${secondTaskGid}`,
    );

    harness.updateTask({
        gid: null,
        nextHref: 'https://app.asana.com/1/155579732034488/inbox/1204439707387879',
    });

    assert.equal(harness.document.getElementById('filter-log-time-button'), null);
});
