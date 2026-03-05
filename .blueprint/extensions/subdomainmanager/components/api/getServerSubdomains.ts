import http from '@/api/http';
import { SubdomainResponse } from '@/blueprint/extensions/subdomainmanager/SubdomainContainer';

export default async (uuid: string): Promise<SubdomainResponse> => {
    const endpoint = uuid.startsWith('external:')
        ? `/api/client/servers/${uuid}/subdomain`
        : `/api/client/extensions/subdomainmanager/servers/${uuid}/subdomain`;

    const { data } = await http.get(endpoint);

    return (data.data || []);
};
