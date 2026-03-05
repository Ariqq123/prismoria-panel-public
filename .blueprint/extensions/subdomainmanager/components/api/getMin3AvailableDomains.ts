import http from '@/api/http';

export interface Min3AvailableDomainItem {
    id: number;
    domain: string;
    provider?: string;
}

export default (uuid: string, subdomain: string): Promise<Min3AvailableDomainItem[]> => {
    const endpoint = uuid.startsWith('external:')
        ? `/api/client/servers/${uuid}/subdomain/min3/checking`
        : `/api/client/extensions/subdomainmanager/servers/${uuid}/subdomain/min3/checking`;

    return new Promise((resolve, reject) => {
        http.get(endpoint, {
            params: { subdomain },
        }).then((response) => {
            const domains = response.data?.data?.domains;
            resolve(Array.isArray(domains) ? domains : []);
        }).catch(reject);
    });
};

