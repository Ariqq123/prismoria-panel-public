export type PanelColorMode = 'dark' | 'light';

const PANEL_COLOR_MODE_STORAGE_KEY = 'panel:color-mode';
export const PANEL_COLOR_MODE_UPDATED_EVENT = 'panel:color-mode-updated';
const PANEL_COLOR_MODE_TRANSITION_ID = 'panel-color-mode-transition';
const PANEL_COLOR_MODE_TRANSITION_DURATION_MS = 760;
const PANEL_COLOR_MODE_TRANSITION_EASING = 'cubic-bezier(0.19, 1, 0.22, 1)';

const DEFAULT_MODE: PanelColorMode = 'dark';

const normalizeMode = (value: unknown): PanelColorMode => (value === 'light' ? 'light' : 'dark');

const dispatchColorModeUpdated = (mode: PanelColorMode): void => {
    window.dispatchEvent(
        new CustomEvent(PANEL_COLOR_MODE_UPDATED_EVENT, {
            detail: { mode },
        })
    );
};

const getDocumentColorMode = (): PanelColorMode => {
    const value = document.body?.dataset?.colorMode || document.documentElement?.dataset?.colorMode;
    return normalizeMode(value);
};

const supportsReducedMotion = (): boolean => {
    if (typeof window.matchMedia !== 'function') {
        return false;
    }

    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
};

const animateColorModeTransition = (nextMode: PanelColorMode): void => {
    if (supportsReducedMotion()) {
        return;
    }

    const existing = document.getElementById(PANEL_COLOR_MODE_TRANSITION_ID);
    if (existing) {
        existing.remove();
    }

    const overlay = document.createElement('div');
    overlay.id = PANEL_COLOR_MODE_TRANSITION_ID;
    const isLightMode = nextMode === 'light';
    Object.assign(overlay.style, {
        position: 'fixed',
        inset: '0',
        pointerEvents: 'none',
        zIndex: '2147483646',
        background: isLightMode
            ? 'linear-gradient(180deg, rgba(255, 255, 255, 0.94), rgb(241, 245, 249), rgba(226, 232, 240, 0.96))'
            : 'linear-gradient(180deg, rgba(28, 28, 28, 0.98), rgb(22, 22, 22), rgba(12, 12, 12, 0.98))',
        willChange: 'transform, opacity, filter',
        contain: 'paint',
    });

    document.body.appendChild(overlay);

    const keyframes: Keyframe[] = isLightMode
        ? [
              {
                  offset: 0,
                  transform: 'translate3d(0, 112%, 0) scale(1.02)',
                  opacity: 0.02,
                  filter: 'blur(1.8px)',
                  easing: 'cubic-bezier(0.16, 1, 0.3, 1)',
              },
              {
                  offset: 0.32,
                  transform: 'translate3d(0, 40%, 0) scale(1.015)',
                  opacity: 0.86,
                  filter: 'blur(0.7px)',
                  easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
              },
              {
                  offset: 0.62,
                  transform: 'translate3d(0, -2%, 0) scale(1)',
                  opacity: 0.98,
                  filter: 'blur(0px)',
                  easing: 'cubic-bezier(0.23, 1, 0.32, 1)',
              },
              {
                  offset: 1,
                  transform: 'translate3d(0, -112%, 0) scale(0.995)',
                  opacity: 0,
                  filter: 'blur(1.4px)',
                  easing: 'cubic-bezier(0.33, 1, 0.68, 1)',
              },
          ]
        : [
              {
                  offset: 0,
                  transform: 'translate3d(0, -112%, 0) scale(1.02)',
                  opacity: 0.02,
                  filter: 'blur(1.8px)',
                  easing: 'cubic-bezier(0.16, 1, 0.3, 1)',
              },
              {
                  offset: 0.32,
                  transform: 'translate3d(0, -40%, 0) scale(1.015)',
                  opacity: 0.86,
                  filter: 'blur(0.7px)',
                  easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
              },
              {
                  offset: 0.62,
                  transform: 'translate3d(0, 2%, 0) scale(1)',
                  opacity: 0.98,
                  filter: 'blur(0px)',
                  easing: 'cubic-bezier(0.23, 1, 0.32, 1)',
              },
              {
                  offset: 1,
                  transform: 'translate3d(0, 112%, 0) scale(0.995)',
                  opacity: 0,
                  filter: 'blur(1.4px)',
                  easing: 'cubic-bezier(0.33, 1, 0.68, 1)',
              },
          ];

    const animation = overlay.animate(keyframes, {
        duration: PANEL_COLOR_MODE_TRANSITION_DURATION_MS,
        easing: PANEL_COLOR_MODE_TRANSITION_EASING,
        fill: 'forwards',
    });

    animation.onfinish = () => {
        overlay.remove();
    };
    animation.oncancel = () => {
        overlay.remove();
    };
};

const applyColorModeToDocument = (mode: PanelColorMode): void => {
    const normalized = normalizeMode(mode);
    const root = document.documentElement;
    const body = document.body;

    root.dataset.colorMode = normalized;
    body.dataset.colorMode = normalized;

    body.classList.toggle('theme-dark', normalized === 'dark');
    body.classList.toggle('theme-light', normalized === 'light');
};

export const getPanelColorMode = (): PanelColorMode => {
    try {
        const raw = window.localStorage.getItem(PANEL_COLOR_MODE_STORAGE_KEY);

        return normalizeMode(raw);
    } catch {
        return DEFAULT_MODE;
    }
};

export const setPanelColorMode = (mode: PanelColorMode, persist = true): PanelColorMode => {
    const normalized = normalizeMode(mode);
    const current = getDocumentColorMode();

    if (current !== normalized) {
        animateColorModeTransition(normalized);
    }

    applyColorModeToDocument(normalized);

    if (persist) {
        try {
            window.localStorage.setItem(PANEL_COLOR_MODE_STORAGE_KEY, normalized);
        } catch {
            // Ignore storage write issues.
        }
    }

    dispatchColorModeUpdated(normalized);

    return normalized;
};

export const togglePanelColorMode = (): PanelColorMode => {
    const next = getPanelColorMode() === 'dark' ? 'light' : 'dark';

    return setPanelColorMode(next, true);
};

export const initializePanelColorMode = (): PanelColorMode => {
    const mode = getPanelColorMode();
    applyColorModeToDocument(mode);

    return mode;
};

export const listenPanelColorModeStorageSync = (): (() => void) => {
    const onStorage = (event: StorageEvent) => {
        if (event.key !== PANEL_COLOR_MODE_STORAGE_KEY) {
            return;
        }

        setPanelColorMode(normalizeMode(event.newValue), false);
    };

    window.addEventListener('storage', onStorage);

    return () => {
        window.removeEventListener('storage', onStorage);
    };
};
