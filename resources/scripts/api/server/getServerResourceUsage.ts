import http from '@/api/http';

export type ServerPowerState = 'offline' | 'starting' | 'running' | 'stopping';

export interface ServerStats {
    status: ServerPowerState;
    isSuspended: boolean;
    memoryUsageInBytes: number;
    cpuUsagePercent: number;
    diskUsageInBytes: number;
    networkRxInBytes: number;
    networkTxInBytes: number;
    uptime: number;
}

interface ResourceUsageCacheEntry {
    data: ServerStats;
    expiresAt: number;
    staleExpiresAt: number;
}

const RESOURCE_CACHE_TTL_MS = 12000;
const RESOURCE_STALE_CACHE_TTL_MS = 90000;
const RESOURCE_CACHE_MAX_ENTRIES = 400;
const resourceUsageCache = new Map<string, ResourceUsageCacheEntry>();

const cloneStats = (stats: ServerStats): ServerStats => ({ ...stats });

const enforceCacheLimit = (): void => {
    if (resourceUsageCache.size <= RESOURCE_CACHE_MAX_ENTRIES) {
        return;
    }

    const overflow = resourceUsageCache.size - RESOURCE_CACHE_MAX_ENTRIES;
    const keys = resourceUsageCache.keys();

    for (let index = 0; index < overflow; index++) {
        const oldestKey = keys.next().value;
        if (!oldestKey) {
            break;
        }

        resourceUsageCache.delete(oldestKey);
    }
};

const writeCache = (server: string, stats: ServerStats): void => {
    const now = Date.now();

    resourceUsageCache.delete(server);
    resourceUsageCache.set(server, {
        data: cloneStats(stats),
        expiresAt: now + RESOURCE_CACHE_TTL_MS,
        staleExpiresAt: now + RESOURCE_STALE_CACHE_TTL_MS,
    });
    enforceCacheLimit();
};

const readCache = (server: string, allowStale = false): ServerStats | null => {
    const cached = resourceUsageCache.get(server);
    if (!cached) {
        return null;
    }

    const now = Date.now();
    if (cached.expiresAt > now || (allowStale && cached.staleExpiresAt > now)) {
        return cloneStats(cached.data);
    }

    return null;
};

export const clearServerResourceUsageCache = (server?: string): void => {
    if (!server) {
        resourceUsageCache.clear();

        return;
    }

    resourceUsageCache.delete(server);
};

const toServerStats = (attributes: any): ServerStats => ({
    status: attributes.current_state,
    isSuspended: attributes.is_suspended,
    memoryUsageInBytes: attributes.resources.memory_bytes,
    cpuUsagePercent: attributes.resources.cpu_absolute,
    diskUsageInBytes: attributes.resources.disk_bytes,
    networkRxInBytes: attributes.resources.network_rx_bytes,
    networkTxInBytes: attributes.resources.network_tx_bytes,
    uptime: attributes.resources.uptime,
});

export default async (server: string, signal?: AbortSignal): Promise<ServerStats> => {
    const cached = readCache(server);
    if (cached) {
        return cached;
    }

    const timeout = server.startsWith('external:') ? 12000 : 8000;

    try {
        const { data: { attributes } } = await http.get(`/api/client/servers/${server}/resources`, {
            signal,
            timeout,
            __skipTimeoutRetry: true,
        } as Record<string, unknown>);

        const stats = toServerStats(attributes);
        writeCache(server, stats);

        return stats;
    } catch (error) {
        const stale = readCache(server, true);
        if (stale) {
            return stale;
        }

        throw error;
    }
};
