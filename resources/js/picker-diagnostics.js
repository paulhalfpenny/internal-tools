// Instrumentation for the "dead task picker" bug (clicking a task option does
// nothing) that we could not reproduce. A native, capture-phase listener —
// independent of Alpine's @click binding, so it still fires even if that
// binding is somehow orphaned — watches task-option clicks. If the picker's
// committed selection (data-selected, bound to selectedTaskId) doesn't become
// the clicked task shortly after the click, we report it with context so the
// cause can be found from real-world occurrences.

if (typeof window !== 'undefined') {
    const loadStart = (typeof performance !== 'undefined' && performance.now) ? performance.now() : 0;
    let morphCount = 0;

    document.addEventListener('livewire:init', () => {
        if (!window.Livewire?.hook) {
            return;
        }
        window.Livewire.hook('morph.updated', () => { morphCount += 1; });
    });

    function report(extra) {
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
            const appVersion = document.querySelector('meta[name="app-version"]')?.getAttribute('content');
            const now = (typeof performance !== 'undefined' && performance.now) ? performance.now() : 0;

            fetch('/diagnostics/picker', {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token ?? '',
                },
                body: JSON.stringify({
                    appVersion: appVersion ?? null,
                    livewireVersion: window.Livewire?.version ?? null,
                    morphCount,
                    msSinceLoad: Math.round(now - loadStart),
                    url: window.location.pathname,
                    ...extra,
                }),
            }).catch(() => {});
        } catch (_) {
            // Diagnostics must never disrupt the UI.
        }
    }

    document.addEventListener('click', (event) => {
        const option = event.target?.closest?.('[data-task-option]');
        if (!option) {
            return;
        }

        const picker = option.closest('[data-picker="task"]');
        if (!picker) {
            return;
        }

        const clickedId = option.getAttribute('data-task-option') ?? '';

        // Give Alpine a beat to commit the selection, then verify it landed.
        window.setTimeout(() => {
            const committed = picker.getAttribute('data-selected') ?? '';
            if (committed !== clickedId) {
                report({ kind: 'task-picker-dead-click', clickedId, committed });
            }
        }, 350);
    }, true);
}
