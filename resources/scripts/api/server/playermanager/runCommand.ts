import http from '@/api/http';

export default (uuid: string, command: string): Promise<void> => {
    return http.post(`/api/client/servers/${uuid}/playermanager/command`, {
        command,
    }).then(() => undefined);
};
