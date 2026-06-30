const enhancedSelects = new WeakMap();
let selectId = 0;
let bodyObserver = null;
let livewireHooksRegistered = false;
let refreshScheduled = false;

const widthClassPattern = /^(w-|min-w-|max-w-|sm:w-|sm:min-w-|sm:max-w-|md:w-|md:min-w-|md:max-w-|lg:w-|lg:min-w-|lg:max-w-|xl:w-|xl:min-w-|xl:max-w-|2xl:w-|2xl:min-w-|2xl:max-w-)/;

export function filterSearchableOptions(options, query) {
    const normalizedQuery = query.trim().toLowerCase();
    const enabledOptions = options.filter((option) => ! option.disabled && ! option.hidden);

    if (normalizedQuery === '') {
        return enabledOptions;
    }

    return enabledOptions.filter((option) => option.label.toLowerCase().includes(normalizedQuery));
}

export function selectHasSearchableChoices(select) {
    if (! select || select.disabled || select.multiple || select.dataset?.searchableSelect === 'false') {
        return false;
    }

    if (Number(select.size ?? 0) > 1) {
        return false;
    }

    return Array.from(select.options ?? [])
        .filter((option) => ! option.disabled && optionText(option) !== '')
        .length > 1;
}

export function initSearchableSelects(root = document) {
    const selects = root?.tagName === 'SELECT'
        ? [root]
        : Array.from(root?.querySelectorAll?.('select') ?? []);

    selects.forEach((select) => {
        if (enhancedSelects.has(select)) {
            const state = enhancedSelects.get(select);
            if (state.wrapper.isConnected) {
                state.refresh();

                return;
            }

            enhancedSelects.delete(select);
            select.classList.remove('searchable-select-native');
            select.removeAttribute('aria-hidden');
            select.removeAttribute('tabindex');
        }

        if (selectHasSearchableChoices(select)) {
            enhanceSelect(select);
        }
    });
}

export function bootSearchableSelects() {
    if (typeof document === 'undefined') {
        return;
    }

    initSearchableSelects(document);
    registerLivewireHooks();

    if (bodyObserver === null) {
        bodyObserver = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.target?.tagName === 'SELECT') {
                    initSearchableSelects(mutation.target);
                }

                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === Node.ELEMENT_NODE) {
                        initSearchableSelects(node);
                    }
                });
            });
        });

        bodyObserver.observe(document.body, {
            attributeFilter: ['disabled'],
            attributes: true,
            childList: true,
            subtree: true,
        });
    }
}

function scheduleSearchableSelectRefresh() {
    if (refreshScheduled) {
        return;
    }

    refreshScheduled = true;
    window.requestAnimationFrame(() => {
        refreshScheduled = false;
        initSearchableSelects(document);
    });
}

function registerLivewireHooks() {
    if (livewireHooksRegistered || typeof window === 'undefined' || ! window.Livewire?.hook) {
        return;
    }

    livewireHooksRegistered = true;
    window.Livewire.hook('morph.updated', () => scheduleSearchableSelectRefresh());
    window.Livewire.hook('morph.added', () => scheduleSearchableSelectRefresh());
}

function enhanceSelect(select) {
    const id = `searchable-select-${++selectId}`;
    const wrapper = document.createElement('div');
    const input = document.createElement('input');
    const list = document.createElement('div');

    let activeIndex = -1;
    let visibleOptions = [];

    select.classList.add('searchable-select-native');
    select.setAttribute('aria-hidden', 'true');
    select.tabIndex = -1;

    wrapper.className = wrapperClassName(select);
    wrapper.dataset.searchableSelectControl = '';

    input.id = id;
    input.type = 'text';
    input.autocomplete = 'off';
    input.spellcheck = false;
    input.className = 'searchable-select-input';
    input.disabled = select.disabled;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', `${id}-options`);
    input.setAttribute('aria-label', selectLabel(select));

    list.id = `${id}-options`;
    list.className = 'searchable-select-options';
    list.hidden = true;
    list.setAttribute('role', 'listbox');

    wrapper.append(input, list);
    select.insertAdjacentElement('afterend', wrapper);

    const refresh = () => {
        if (! selectHasSearchableChoices(select)) {
            input.disabled = select.disabled;
        }

        select.classList.add('searchable-select-native');
        select.setAttribute('aria-hidden', 'true');
        select.tabIndex = -1;
        input.setAttribute('aria-label', selectLabel(select));
        input.disabled = select.disabled;
        updateInputFromSelect(select, input);
        renderOptions(select, list, input.value, selectOption);
    };

    const openList = (query = '') => {
        if (select.disabled) {
            return;
        }

        visibleOptions = renderOptions(select, list, query, selectOption);
        activeIndex = visibleOptions.findIndex((option) => option.index === select.selectedIndex);
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        markActiveOption(list, activeIndex);
    };

    const closeList = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        activeIndex = -1;
        markActiveOption(list, activeIndex);
    };

    const moveActiveOption = (direction) => {
        if (list.hidden) {
            openList('');
        }

        if (visibleOptions.length === 0) {
            return;
        }

        activeIndex = activeIndex + direction;
        if (activeIndex < 0) {
            activeIndex = visibleOptions.length - 1;
        }
        if (activeIndex >= visibleOptions.length) {
            activeIndex = 0;
        }

        markActiveOption(list, activeIndex);
    };

    function selectOption(option) {
        select.selectedIndex = option.index;
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
        updateInputFromSelect(select, input);
        closeList();
    }

    input.addEventListener('focus', () => {
        updateInputFromSelect(select, input);
        input.select();
        openList('');
    });

    input.addEventListener('click', () => openList(''));

    input.addEventListener('input', () => {
        visibleOptions = renderOptions(select, list, input.value, selectOption);
        activeIndex = visibleOptions.length > 0 ? 0 : -1;
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        markActiveOption(list, activeIndex);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveActiveOption(1);

            return;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveActiveOption(-1);

            return;
        }

        if (event.key === 'Enter' && ! list.hidden) {
            event.preventDefault();
            if (activeIndex >= 0 && visibleOptions[activeIndex]) {
                selectOption(visibleOptions[activeIndex]);
            }

            return;
        }

        if (event.key === 'Escape') {
            updateInputFromSelect(select, input);
            closeList();
        }
    });

    input.addEventListener('blur', () => {
        window.setTimeout(() => {
            if (! wrapper.contains(document.activeElement)) {
                updateInputFromSelect(select, input);
                closeList();
            }
        }, 100);
    });

    document.addEventListener('click', (event) => {
        if (! wrapper.contains(event.target)) {
            updateInputFromSelect(select, input);
            closeList();
        }
    });

    select.addEventListener('change', () => refresh());

    updateInputFromSelect(select, input);
    enhancedSelects.set(select, { refresh, wrapper });
}

function renderOptions(select, list, query, selectOption) {
    const options = filterSearchableOptions(readOptions(select), query);
    list.replaceChildren();

    if (options.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'searchable-select-empty';
        empty.textContent = 'No matching options';
        list.append(empty);

        return options;
    }

    options.forEach((option, visibleIndex) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'searchable-select-option';
        button.dataset.visibleIndex = String(visibleIndex);
        button.textContent = option.label;
        button.setAttribute('role', 'option');
        button.setAttribute('aria-selected', option.index === select.selectedIndex ? 'true' : 'false');

        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => selectOption(option));

        list.append(button);
    });

    return options;
}

function markActiveOption(list, activeIndex) {
    Array.from(list.querySelectorAll('.searchable-select-option')).forEach((button) => {
        const isActive = Number(button.dataset.visibleIndex) === activeIndex;
        button.classList.toggle('searchable-select-option-active', isActive);

        if (isActive) {
            button.scrollIntoView({ block: 'nearest' });
        }
    });
}

function readOptions(select) {
    return Array.from(select.options ?? []).map((option, index) => ({
        disabled: option.disabled,
        hidden: option.hidden,
        index,
        label: optionText(option),
        value: option.value,
    }));
}

function updateInputFromSelect(select, input) {
    const option = select.options?.[select.selectedIndex] ?? null;
    input.value = option ? optionText(option) : '';
}

function optionText(option) {
    return (option.label ?? option.textContent ?? '').trim();
}

function wrapperClassName(select) {
    const widthClasses = Array.from(select.classList ?? [])
        .filter((className) => widthClassPattern.test(className));
    const theme = select.classList?.contains('schedule-select')
        ? 'searchable-select-schedule'
        : select.classList?.contains('schedule-modal-select')
            ? 'searchable-select-modal'
            : 'searchable-select-admin';

    return ['searchable-select', theme, ...widthClasses].join(' ');
}

function selectLabel(select) {
    if (select.getAttribute('aria-label')) {
        return select.getAttribute('aria-label');
    }

    if (select.id) {
        const label = document.querySelector(`label[for="${CSS.escape(select.id)}"]`);
        if (label) {
            return label.textContent.trim();
        }
    }

    const wrappingLabel = select.closest('label');
    if (wrappingLabel) {
        return wrappingLabel.textContent.trim();
    }

    const previousLabel = select.parentElement?.querySelector('label');
    if (previousLabel) {
        return previousLabel.textContent.trim();
    }

    return select.name || 'Select an option';
}

if (typeof window !== 'undefined') {
    const boot = () => bootSearchableSelects();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    document.addEventListener('livewire:navigated', boot);
    document.addEventListener('livewire:init', boot);
    document.addEventListener('livewire:initialized', boot);
}
