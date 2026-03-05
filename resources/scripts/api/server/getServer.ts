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

export default (uuid: string): Promise<[Server, string[]]> => {
  return new Promise((resolve, reject) => {
    http
      .get(`/api/client/servers/${uuid}`)
      .then(({ data }) =>
        resolve([
          rawDataToServerObject(data),
          // eslint-disable-next-line camelcase
          data.meta?.is_server_owner ? ['*'] : data.meta?.user_permissions || [],
        ]),
      )
      .catch(reject);
  });
};
