// Stale-tab guard: when a deploy ships a new front-end build, an already-open
// tab is running old JS against new server markup — the source of half-dead
// Alpine pickers that only a hard refresh clears. Every web response carries an
// `X-App-Version` header (see AppVersionHeader middleware). On each Livewire
// request we compare it to the version this tab booted with; on a mismatch we
// show a dismissible "reload" banner. We never auto-reload — that could discard
// an open modal or unsaved edits.

const loadedVersion = document
    .querySelector('meta[name="app-version"]')
    ?.getAttribute('content') ?? null;

let bannerShownForVersion = null;

function showReloadBanner(newVersion) {
    // Only nag once per newly-seen version.
    if (bannerShownForVersion === newVersion || document.getElementById('app-version-banner')) {
        return;
    }
    bannerShownForVersion = newVersion;

    const banner = document.createElement('div');
    banner.id = 'app-version-banner';
    banner.setAttribute('role', 'status');
    banner.style.cssText = [
        'position:fixed', 'left:50%', 'bottom:1.25rem', 'transform:translateX(-50%)',
        'z-index:2147483647', 'display:flex', 'align-items:center', 'gap:0.75rem',
        'background:#002f5f', 'color:#fff', 'padding:0.625rem 1rem', 'border-radius:0.5rem',
        'box-shadow:0 10px 25px rgba(0,0,0,0.25)', 'font-size:0.875rem',
        'font-family:inherit', 'max-width:calc(100vw - 2rem)',
    ].join(';');

    const text = document.createElement('span');
    text.textContent = 'A new version of Filter is available.';

    const reload = document.createElement('button');
    reload.type = 'button';
    reload.textContent = 'Reload';
    reload.style.cssText = [
        'background:#fff', 'color:#002f5f', 'border:0', 'border-radius:0.375rem',
        'padding:0.3rem 0.75rem', 'font-weight:600', 'cursor:pointer', 'font-size:0.8125rem',
    ].join(';');
    reload.addEventListener('click', () => window.location.reload());

    const dismiss = document.createElement('button');
    dismiss.type = 'button';
    dismiss.setAttribute('aria-label', 'Dismiss');
    dismiss.textContent = '✕';
    dismiss.style.cssText = [
        'background:transparent', 'color:#cbd5e1', 'border:0', 'cursor:pointer',
        'font-size:0.9rem', 'line-height:1', 'padding:0.25rem',
    ].join(';');
    dismiss.addEventListener('click', () => banner.remove());

    banner.append(text, reload, dismiss);
    document.body.appendChild(banner);
}

function handleResponseVersion(version) {
    if (!version || !loadedVersion || loadedVersion === 'dev') {
        return;
    }
    if (version !== loadedVersion) {
        showReloadBanner(version);
    }
}

if (typeof window !== 'undefined') {
    document.addEventListener('livewire:init', () => {
        if (!window.Livewire?.hook) {
            return;
        }

        window.Livewire.hook('request', ({ respond }) => {
            respond(({ response }) => {
                try {
                    handleResponseVersion(response?.headers?.get?.('X-App-Version'));
                } catch (_) {
                    // Header access varies across transports; ignore and try next request.
                }
            });
        });
    });
}
