import assert from 'node:assert/strict';
import test from 'node:test';

import {
    filterSearchableOptions,
    selectHasSearchableChoices,
} from '../../resources/js/searchable-select.js';

test('filters options by typed text case-insensitively', () => {
    const options = [
        { value: '1', label: 'mySchedule Master' },
        { value: '2', label: 'Franchises - mySchedule' },
        { value: '3', label: 'Customer App' },
    ];

    assert.deepEqual(
        filterSearchableOptions(options, 'schedule').map((option) => option.label),
        ['mySchedule Master', 'Franchises - mySchedule'],
    );
});

test('enhances single-select dropdowns with at least two enabled choices', () => {
    assert.equal(
        selectHasSearchableChoices({
            disabled: false,
            multiple: false,
            size: 0,
            dataset: {},
            options: [
                { disabled: false, value: '', textContent: 'Select a project' },
                { disabled: false, value: '1', textContent: 'Project A' },
            ],
        }),
        true,
    );

    assert.equal(
        selectHasSearchableChoices({
            disabled: false,
            multiple: true,
            size: 0,
            dataset: {},
            options: [
                { disabled: false, value: '1', textContent: 'Project A' },
                { disabled: false, value: '2', textContent: 'Project B' },
            ],
        }),
        false,
    );

    assert.equal(
        selectHasSearchableChoices({
            disabled: false,
            multiple: false,
            size: 0,
            dataset: { searchableSelect: 'false' },
            options: [
                { disabled: false, value: '1', textContent: 'Project A' },
                { disabled: false, value: '2', textContent: 'Project B' },
            ],
        }),
        false,
    );

    assert.equal(
        selectHasSearchableChoices({
            disabled: false,
            multiple: false,
            size: 0,
            dataset: {},
            options: [
                { disabled: true, value: '1', textContent: 'Project A' },
                { disabled: false, value: '2', textContent: 'Project B' },
            ],
        }),
        false,
    );
});
