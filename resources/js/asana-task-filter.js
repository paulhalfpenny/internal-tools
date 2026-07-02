function taskText(task) {
    return [
        task?.name,
        task?.board_name,
        task?.search_text,
    ].filter(Boolean).join(' ');
}

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

export function normalizeAsanaTaskText(value) {
    return String(value ?? '')
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/&/g, ' and ')
        .replace(/[^a-z0-9]+/g, ' ')
        .trim()
        .replace(/\s+/g, ' ');
}

function compactAsanaTaskText(value) {
    return normalizeAsanaTaskText(value).replace(/\s+/g, '');
}

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
