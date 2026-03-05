import http from '@/api/http';

export default async (uuid: string, command: string): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/command`, { command });
};
