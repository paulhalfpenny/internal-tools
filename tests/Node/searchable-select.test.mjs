import assert from 'node:assert/strict';
import test from 'node:test';

import { selectHasSearchableChoices } from '../../resources/js/searchable-select.js';

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
