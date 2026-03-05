import http from '@/api/http';

export interface CreateSubdomainPayload {
    subdomain: string;
    domainId: number;
    advancedSrv?: boolean;
    srvService?: string;
    srvProtocolType?: 'tcp' | 'udp' | 'tls';
    srvPriority?: number;
    srvWeight?: number;
    srvPort?: number;
}

export default (uuid: string, payload: CreateSubdomainPayload): Promise<any> => {
    const endpoint = uuid.startsWith('external:')
        ? `/api/client/servers/${uuid}/subdomain/create`
        : `/api/client/extensions/subdomainmanager/servers/${uuid}/subdomain/create`;

    return new Promise((resolve, reject) => {
        const body: Record<string, unknown> = {
            subdomain: payload.subdomain,
            domainId: payload.domainId,
        };

        if (payload.advancedSrv) {
            body.advanced_srv = true;
            body.srv_service = payload.srvService;
            body.srv_protocol_type = payload.srvProtocolType;
            body.srv_priority = payload.srvPriority;
            body.srv_weight = payload.srvWeight;
            body.srv_port = payload.srvPort;
        }

        http.post(endpoint, body).then((data) => {
            resolve(data.data || []);
        }).catch(reject);
    });
};
