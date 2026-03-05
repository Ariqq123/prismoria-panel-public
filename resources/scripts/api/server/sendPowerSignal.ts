import http from '@/api/http';

export type PowerSignal = 'start' | 'stop' | 'restart' | 'kill';

export default async (uuid: string, signal: PowerSignal): Promise<void> => {
    await http.post(`/api/client/servers/${uuid}/power`, { signal });
};
