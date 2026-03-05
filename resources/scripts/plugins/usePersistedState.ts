import { Dispatch, SetStateAction, useEffect, useState } from 'react';

export function usePersistedState<S = undefined>(
    key: string,
    defaultValue: S
): [S | undefined, Dispatch<SetStateAction<S | undefined>>] {
    const [state, setState] = useState(() => {
        try {
            const item = localStorage.getItem(key);
            if (item === null) {
                return defaultValue;
            }

            return JSON.parse(item);
        } catch (e) {
            console.warn('Failed to retrieve persisted value from store.', e);
            try {
                localStorage.removeItem(key);
            } catch (error) {
                // Ignore storage cleanup errors.
            }

            return defaultValue;
        }
    });

    useEffect(() => {
        try {
            localStorage.setItem(key, JSON.stringify(state));
        } catch (e) {
            console.warn('Failed to persist value to store.', e);
        }
    }, [key, state]);

    return [state, setState];
}
