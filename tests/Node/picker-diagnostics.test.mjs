import assert from 'node:assert/strict';
import test from 'node:test';

import * as diagnostics from '../../resources/js/picker-diagnostics.js';

test('task picker diagnostics distinguish a dead click from a stuck-open picker', () => {
    assert.equal(typeof diagnostics.taskPickerIssue, 'function');
    assert.equal(diagnostics.taskPickerIssue('3', '', false), 'task-picker-dead-click');
    assert.equal(diagnostics.taskPickerIssue('3', '3', true), 'task-picker-stuck-open');
    assert.equal(diagnostics.taskPickerIssue('3', '3', false), null);
});
