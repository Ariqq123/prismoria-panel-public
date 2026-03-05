import http from '@/api/http';

const endpointFor = (uuid: string, action: 'classic' | 'nu' | 'nu/v2') => {
    if (uuid.startsWith('external:')) {
        return `/api/client/servers/${uuid}/votifier/${action}`;
    }

    return `/api/client/extensions/votifiertester/servers/${uuid}/${action}`;
};

export const sendClassicVotifierVote = (uuid: string, host: string, port: string, publicKey: string, username: string): Promise<any> => {
    return http.post(endpointFor(uuid, 'classic'), { host, port, publicKey, username });
};

export const sendNuVotifierVote = (uuid: string, host: string, port: string, publicKey: string, username: string): Promise<any> => {
    return http.post(endpointFor(uuid, 'nu'), { host, port, publicKey, username });
};

export const sendNuVotifierV2Vote = (uuid: string, host: string, port: string, token: string, username: string): Promise<any> => {
    return http.post(endpointFor(uuid, 'nu/v2'), { host, port, token, username });
};
