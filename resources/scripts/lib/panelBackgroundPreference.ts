export interface PanelBackgroundPreference {
    enabled: boolean;
    imageUrl: string;
}

const PANEL_BACKGROUND_PREFERENCE_KEY_PREFIX = 'panel-background-preference:';
export const PANEL_BACKGROUND_PREFERENCE_UPDATED_EVENT = 'panel-background:preference-updated';

const defaultPreference = (): PanelBackgroundPreference => ({
    enabled: false,
    imageUrl: '',
});

const normalizeImageUrl = (value: unknown): string => (typeof value === 'string' ? value.trim() : '');
const normalizeServerIdentifier = (value: unknown): string => (typeof value === 'string' ? value.trim() : '');

const storageKeyForUserServer = (userUuid: string | undefined, serverIdentifier: string | undefined): string | null => {
    const normalizedServerIdentifier = normalizeServerIdentifier(serverIdentifier);
    if (!normalizedServerIdentifier) {
        return null;
    }

    return `${PANEL_BACKGROUND_PREFERENCE_KEY_PREFIX}${(userUuid || 'anonymous').trim()}:${normalizedServerIdentifier}`;
};

const dispatchPreferenceUpdated = (preference: PanelBackgroundPreference): void => {
    window.dispatchEvent(
        new CustomEvent(PANEL_BACKGROUND_PREFERENCE_UPDATED_EVENT, {
            detail: preference,
        })
    );
};

export const getPanelBackgroundPreference = (
    userUuid: string | undefined,
    serverIdentifier: string | undefined
): PanelBackgroundPreference => {
    const storageKey = storageKeyForUserServer(userUuid, serverIdentifier);
    if (!storageKey) {
        return defaultPreference();
    }

    try {
        const raw = window.localStorage.getItem(storageKey);
        if (!raw) {
            return defaultPreference();
        }

        const parsed = JSON.parse(raw);
        // Backward compatibility in case a plain URL string was stored.
        if (typeof parsed === 'string') {
            const legacyUrl = normalizeImageUrl(parsed);
            return {
                enabled: legacyUrl !== '',
                imageUrl: legacyUrl,
            };
        }

        const imageUrl = normalizeImageUrl(parsed?.imageUrl);
        return {
            enabled: Boolean(parsed?.enabled) && imageUrl !== '',
            imageUrl,
        };
    } catch {
        return defaultPreference();
    }
};

export const setPanelBackgroundPreference = (
    userUuid: string | undefined,
    serverIdentifier: string | undefined,
    imageUrl: string,
    enabled = true
): PanelBackgroundPreference => {
    const storageKey = storageKeyForUserServer(userUuid, serverIdentifier);
    const normalizedImageUrl = normalizeImageUrl(imageUrl);
    const preference: PanelBackgroundPreference = {
        enabled: enabled && normalizedImageUrl !== '',
        imageUrl: normalizedImageUrl,
    };

    if (!storageKey) {
        dispatchPreferenceUpdated(defaultPreference());

        return defaultPreference();
    }

    try {
        if (!preference.enabled || preference.imageUrl === '') {
            window.localStorage.removeItem(storageKey);
        } else {
            window.localStorage.setItem(storageKey, JSON.stringify(preference));
        }
    } catch {
        // ignore storage errors and still dispatch update event for current tab.
    }

    dispatchPreferenceUpdated(preference);

    return preference;
};

export const clearPanelBackgroundPreference = (userUuid: string | undefined, serverIdentifier: string | undefined): void => {
    const storageKey = storageKeyForUserServer(userUuid, serverIdentifier);
    if (!storageKey) {
        dispatchPreferenceUpdated(defaultPreference());

        return;
    }

    try {
        window.localStorage.removeItem(storageKey);
    } catch {
        // ignore storage errors
    }

    dispatchPreferenceUpdated(defaultPreference());
};
