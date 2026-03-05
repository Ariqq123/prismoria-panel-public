import { rawDataToServerObject, Server } from '@/api/server/getServer';
import http, { getPaginationSet, PaginatedResult } from '@/api/http';

interface QueryParams {
    query?: string;
    page?: number;
    perPage?: number;
    type?: string;
    source?: 'local' | 'external' | 'all';
    externalFetch?: 'cached' | 'live' | 'cached-only';
}

interface CacheEntry {
    data: PaginatedResult<Server>;
    expiresAt: number;
}

const CACHE_TTL_MS = 15000;
const CACHE_MAX_ENTRIES = 80;
const responseCache = new Map<string, CacheEntry>();
const inFlightRequests = new Map<string, Promise<PaginatedResult<Server>>>();

const buildCacheKey = ({
    query,
    page,
    perPage,
    type,
    source = 'all',
    externalFetch = 'cached',
}: QueryParams): string =>
    JSON.stringify({
        query: typeof query === 'string' ? query.trim() : '',
        page: typeof page === 'number' ? page : 1,
        perPage: typeof perPage === 'number' ? perPage : 50,
        type: typeof type === 'string' ? type : '',
        source,
        externalFetch,
    });

const cloneResult = (result: PaginatedResult<Server>): PaginatedResult<Server> => ({
    items: result.items.slice(),
    pagination: { ...result.pagination },
});

const pruneExpiredCacheEntries = (now: number): void => {
    responseCache.forEach((entry, key) => {
        if (entry.expiresAt <= now) {
            responseCache.delete(key);
        }
    });
};

const enforceCacheLimit = (): void => {
    if (responseCache.size <= CACHE_MAX_ENTRIES) {
        return;
    }

    const overflow = responseCache.size - CACHE_MAX_ENTRIES;
    const keys = responseCache.keys();
    for (let index = 0; index < overflow; index++) {
        const oldestKey = keys.next().value;
        if (!oldestKey) {
            break;
        }

        responseCache.delete(oldestKey);
    }
};

export const clearServersCache = (): void => {
    responseCache.clear();
    inFlightRequests.clear();
};

export default ({
    query,
    perPage,
    source = 'all',
    externalFetch = 'cached',
    ...params
}: QueryParams): Promise<PaginatedResult<Server>> => {
    const input: QueryParams = {
        query,
        perPage,
        source,
        externalFetch,
        ...params,
    };
    const cacheKey = buildCacheKey(input);
    const now = Date.now();

    pruneExpiredCacheEntries(now);

    const cached = responseCache.get(cacheKey);
    if (cached && cached.expiresAt > now) {
        return Promise.resolve(cloneResult(cached.data));
    }

    const inFlight = inFlightRequests.get(cacheKey);
    if (inFlight) {
        return inFlight.then(cloneResult);
    }

    const request = http
        .get('/api/client', {
            params: {
                'filter[*]': query,
                source,
                ...(source !== 'local' ? { external_fetch: externalFetch } : {}),
                ...(typeof perPage === 'number' ? { per_page: perPage } : {}),
                ...params,
            },
        })
        .then(({ data }) => {
            const result: PaginatedResult<Server> = {
                items: (data.data || []).map((datum: any) => rawDataToServerObject(datum)),
                pagination: getPaginationSet(data.meta.pagination),
            };

            responseCache.set(cacheKey, {
                data: result,
                expiresAt: Date.now() + CACHE_TTL_MS,
            });
            enforceCacheLimit();

            return result;
        })
        .finally(() => {
            inFlightRequests.delete(cacheKey);
        });

    inFlightRequests.set(cacheKey, request);

    return request.then(cloneResult);
};
