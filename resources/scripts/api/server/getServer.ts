import http, { FractalResponseData, FractalResponseList } from '@/api/http';
import { rawDataToServerAllocation, rawDataToServerEggVariable } from '@/api/transformers';
import { ServerEggVariable, ServerStatus } from '@/api/server/types';

export interface Allocation {
  id: number;
  ip: string;
  alias: string | null;
  port: number;
  notes: string | null;
  isDefault: boolean;
}

export interface Server {
  id: string;
  internalId: number | string;
  uuid: string;
  name: string;
  node: string;
  isNodeUnderMaintenance: boolean;
  status: ServerStatus;
  sftpDetails: {
    ip: string;
    port: number;
    username?: string;
  };
  invocation: string;
  dockerImage: string;
  description: string;
  limits: {
    memory: number;
    swap: number;
    disk: number;
    io: number;
    cpu: number;
    threads: string;
  };
  eggFeatures: string[];
  featureLimits: {
    databases: number;
    allocations: number;
    backups: number;
  };
  isTransferring: boolean;
  variables: ServerEggVariable[];
  allocations: Allocation[];

  // Define egg id from Blueprint
  BlueprintFramework: {
    eggId: number | null;
  };
  source?: string;
  externalPanelConnectionId?: number;
  externalPanelName?: string;
  externalPanelUrl?: string;
  externalServerIdentifier?: string;
}

type ServerResponseTuple = [Server, string[]];

interface ServerResponseCacheEntry {
  data: ServerResponseTuple;
  expiresAt: number;
  staleExpiresAt: number;
}

const SERVER_CACHE_TTL_MS = 20000;
const SERVER_STALE_CACHE_TTL_MS = 5 * 60 * 1000;
const SERVER_CACHE_MAX_ENTRIES = 120;
const serverResponseCache = new Map<string, ServerResponseCacheEntry>();
const inFlightServerRequests = new Map<string, Promise<ServerResponseTuple>>();

const cloneServer = (server: Server): Server => ({
  ...server,
  sftpDetails: { ...server.sftpDetails },
  limits: { ...server.limits },
  eggFeatures: server.eggFeatures.slice(),
  featureLimits: { ...server.featureLimits },
  variables: server.variables.map((variable) => ({ ...variable })),
  allocations: server.allocations.map((allocation) => ({ ...allocation })),
  BlueprintFramework: { ...server.BlueprintFramework },
});

const cloneServerResponse = (response: ServerResponseTuple): ServerResponseTuple => [
  cloneServer(response[0]),
  response[1].slice(),
];

const enforceCacheLimit = (): void => {
  if (serverResponseCache.size <= SERVER_CACHE_MAX_ENTRIES) {
    return;
  }

  const overflow = serverResponseCache.size - SERVER_CACHE_MAX_ENTRIES;
  const keys = serverResponseCache.keys();
  for (let index = 0; index < overflow; index++) {
    const oldestKey = keys.next().value;
    if (!oldestKey) {
      break;
    }

    serverResponseCache.delete(oldestKey);
  }
};

const writeServerCache = (key: string, value: ServerResponseTuple): void => {
  const now = Date.now();

  serverResponseCache.delete(key);
  serverResponseCache.set(key, {
    data: value,
    expiresAt: now + SERVER_CACHE_TTL_MS,
    staleExpiresAt: now + SERVER_STALE_CACHE_TTL_MS,
  });
  enforceCacheLimit();
};

const readServerCache = (key: string, allowStale = false): ServerResponseTuple | null => {
  const cached = serverResponseCache.get(key);
  if (!cached) {
    return null;
  }

  const now = Date.now();
  if (cached.expiresAt > now || (allowStale && cached.staleExpiresAt > now)) {
    return cloneServerResponse(cached.data);
  }

  return null;
};

export const clearServerCache = (serverId?: string): void => {
  if (!serverId) {
    serverResponseCache.clear();
    inFlightServerRequests.clear();

    return;
  }

  serverResponseCache.delete(serverId);
  inFlightServerRequests.delete(serverId);
};

export const rawDataToServerObject = ({ attributes: data }: FractalResponseData): Server => ({
  // Some payloads (especially external) may omit BlueprintFramework.
  // Keep this object stable to avoid runtime crashes in route filtering.
  BlueprintFramework: {
    eggId:
      typeof data?.BlueprintFramework?.egg_id === 'number'
        ? data.BlueprintFramework.egg_id
        : typeof data?.egg_id === 'number'
          ? data.egg_id
          : null,
  },
  id: data.identifier,
  internalId: data.internal_id,
  uuid: data.uuid,
  name: data.name,
  node: data.node,
  isNodeUnderMaintenance: data.is_node_under_maintenance,
  status: data.status,
  invocation: data.invocation,
  dockerImage: data.docker_image,
  sftpDetails: {
    ip: data.sftp_details.ip,
    port: data.sftp_details.port,
    username:
      typeof data?.sftp_details?.username === 'string' && data.sftp_details.username.trim().length > 0
        ? data.sftp_details.username.trim()
        : undefined,
  },
  description: data.description ? (data.description.length > 0 ? data.description : null) : null,
  limits: { ...data.limits },
  eggFeatures: data.egg_features || [],
  featureLimits: { ...data.feature_limits },
  isTransferring: data.is_transferring,
  variables: ((data.relationships?.variables as FractalResponseList | undefined)?.data || []).map(
    rawDataToServerEggVariable,
  ),
  allocations: ((data.relationships?.allocations as FractalResponseList | undefined)?.data || []).map(
    rawDataToServerAllocation,
  ),
  source: typeof data?.source === 'string' ? data.source : undefined,
  externalPanelConnectionId:
    typeof data?.external_panel_connection_id === 'number' ? data.external_panel_connection_id : undefined,
  externalPanelName: typeof data?.external_panel_name === 'string' ? data.external_panel_name : undefined,
  externalPanelUrl: typeof data?.external_panel_url === 'string' ? data.external_panel_url : undefined,
  externalServerIdentifier:
    typeof data?.external_server_identifier === 'string' ? data.external_server_identifier : undefined,
});

const requestServer = (uuid: string): Promise<ServerResponseTuple> => {
  const inFlight = inFlightServerRequests.get(uuid);
  if (inFlight) {
    return inFlight;
  }

  const request = http
    .get(`/api/client/servers/${uuid}`)
    .then(({ data }) => {
      const response: ServerResponseTuple = [
        rawDataToServerObject(data),
        // eslint-disable-next-line camelcase
        data.meta?.is_server_owner ? ['*'] : data.meta?.user_permissions || [],
      ];

      writeServerCache(uuid, response);

      return response;
    })
    .catch((error) => {
      const staleCached = readServerCache(uuid, true);
      if (staleCached) {
        return staleCached;
      }

      throw error;
    })
    .finally(() => {
      inFlightServerRequests.delete(uuid);
    });

  inFlightServerRequests.set(uuid, request);

  return request;
};

export const prefetchServer = (uuid: string): void => {
  if (!uuid || readServerCache(uuid)) {
    return;
  }

  void requestServer(uuid).catch(() => undefined);
};

export default async (uuid: string): Promise<ServerResponseTuple> => {
  const cached = readServerCache(uuid);
  if (cached) {
    return cached;
  }

  const response = await requestServer(uuid);

  return cloneServerResponse(response);
};
