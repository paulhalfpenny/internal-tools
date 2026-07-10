import assert from 'node:assert/strict';
import test from 'node:test';

import { filterAsanaTasksForProject } from '../../resources/js/asana-task-filter.js';

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
