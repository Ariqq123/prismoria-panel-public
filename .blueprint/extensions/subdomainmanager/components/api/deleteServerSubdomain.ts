import http from '@/api/http';

export default (uuid: string, id: number): Promise<void> => {
    const endpoint = uuid.startsWith('external:')
        ? `/api/client/servers/${uuid}/subdomain/delete/${id}`
        : `/api/client/extensions/subdomainmanager/servers/${uuid}/subdomain/delete/${id}`;

    return new Promise((resolve, reject) => {
        http.delete(endpoint)
            .then(() => resolve())
            .catch(reject);
    });
};
