import http from '@/api/http';
import { PlayerManagerResponse } from '@/components/server/playermanager/types';

export default async (uuid: string): Promise<PlayerManagerResponse> => {
  const { data } = await http.get(`/api/client/servers/${uuid}/playermanager`);

  return (
    data.data || {
      players: {
        list: [],
        players: {
          max: 0,
          online: 0,
        },
      },
      ops: [],
      whitelist: [],
      bans: [],
      banIps: [],
    }
  );
};
