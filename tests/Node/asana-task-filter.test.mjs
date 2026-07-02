import assert from 'node:assert/strict';
import test from 'node:test';

import {
    extractAsanaTaskGids,
    filterAsanaTasksForProject,
    normalizeAsanaTaskText,
} from '../../resources/js/asana-task-filter.js';

test('filters shared-board tasks to the selected project before typing', () => {
    const tasks = [
        { gid: 'CAREHOME', name: 'Carehome booking flow update', board_name: 'Agency Delivery Status' },
        { gid: 'DENTIST', name: '123 Dentist | MS Dynamics Planning & Integration', board_name: 'Agency Delivery Status' },
    ];

    assert.deepEqual(
        filterAsanaTasksForProject(tasks, ['carehome', 'tomorrows guides'], '').map((task) => task.gid),
        ['CAREHOME'],
    );
});

test('typing searches all linked board tasks instead of the project prefilter', () => {
    const tasks = [
        { gid: 'CAREHOME', name: 'Carehome booking flow update', board_name: 'Agency Delivery Status' },
        { gid: 'DENTIST', name: '123 Dentist | MS Dynamics Planning & Integration', board_name: 'Agency Delivery Status' },
    ];

    assert.deepEqual(
        filterAsanaTasksForProject(tasks, ['carehome', 'tomorrows guides'], '123').map((task) => task.gid),
        ['DENTIST'],
    );
});

test('typing matches Asana ticket codes from cached search text', () => {
    const tasks = [
        { gid: 'BOOKING', name: 'Build booking journey', board_name: 'Agency Delivery Status', search_text: 'Ticket ID JDW-12345' },
        { gid: 'DESIGN', name: 'Design homepage', board_name: 'Agency Delivery Status', search_text: 'Ticket ID JDW-98765' },
    ];

    assert.deepEqual(
        filterAsanaTasksForProject(tasks, [], 'JDW-12345').map((task) => task.gid),
        ['BOOKING'],
    );
});

test('typing matches compact Asana ticket codes without punctuation', () => {
    const tasks = [
        { gid: 'BOOKING', name: 'Build booking journey', board_name: 'Agency Delivery Status', search_text: 'Ticket ID JDW-12345' },
    ];

    assert.deepEqual(
        filterAsanaTasksForProject(tasks, [], 'JDW12345').map((task) => task.gid),
        ['BOOKING'],
    );
});

test('matches compact names across punctuation and spacing differences', () => {
    const tasks = [
        { gid: 'DENTIST', name: '123Dentist integration planning', board_name: 'Agency Delivery Status' },
    ];

    assert.deepEqual(
        filterAsanaTasksForProject(tasks, ['123 dentist'], '').map((task) => task.gid),
        ['DENTIST'],
    );

    assert.equal(normalizeAsanaTaskText('Carehome.co.uk / Build'), 'carehome co uk build');
});

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

test('does not treat non-Asana numeric noise as task gid searches', () => {
    const tasks = [
        { gid: '1216205972827127', name: 'Feature: Search Asana task by URL', board_name: 'Internal Tools' },
    ];

    assert.deepEqual(
        filterAsanaTasksForProject(tasks, [], 'https://example.com/tasks/1216205972827127').map((task) => task.gid),
        [],
    );

    assert.deepEqual(
        filterAsanaTasksForProject(tasks, [], 'Please check 1216205972827127 when you get a minute').map((task) => task.gid),
        [],
    );
});
