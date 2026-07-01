function taskText(task) {
    return [
        task?.name,
        task?.board_name,
        task?.search_text,
    ].filter(Boolean).join(' ');
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

    if (normalizedQuery !== '') {
        return taskList.filter((task) => asanaTaskMatchesText(task, normalizedQuery));
    }

    const terms = Array.isArray(projectTerms)
        ? projectTerms.filter((term) => normalizeAsanaTaskText(term) !== '')
        : [];

    if (terms.length === 0) {
        return taskList;
    }

    return taskList.filter((task) => terms.some((term) => asanaTaskMatchesText(task, term)));
}
