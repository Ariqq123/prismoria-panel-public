import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import tw from 'twin.macro';
import useSWR from 'swr';
import FlashMessageRender from '@/components/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import getPlayers from '@/api/server/playermanager/getPlayers';
import Spinner from '@/components/elements/Spinner';
import Button from '@/components/elements/Button';
import Input from '@/components/elements/Input';
import PlayerList from '@/components/server/playermanager/PlayerList';
import OpList from '@/components/server/playermanager/OpList';
import WhiteList from '@/components/server/playermanager/WhiteList';
import Bans from '@/components/server/playermanager/Bans';
import BanIps from '@/components/server/playermanager/BanIps';
import PlayerInspector from '@/components/server/playermanager/PlayerInspector';
import { buildInspectablePlayer, InspectablePlayer } from '@/components/server/playermanager/playerIdentity';
import { PlayerManagerResponse } from '@/components/server/playermanager/types';

const defaultResponse: PlayerManagerResponse = {
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
};

const collectInspectablePlayers = (response: PlayerManagerResponse): InspectablePlayer[] => {
  const buckets = [
    response.players.list.map((entry) => buildInspectablePlayer(entry, 'online')),
    response.ops.map((entry) => buildInspectablePlayer(entry, 'op')),
    response.whitelist.map((entry) => buildInspectablePlayer(entry, 'whitelist')),
    response.bans.map((entry) => buildInspectablePlayer(entry, 'ban')),
    response.banIps.map((entry) => buildInspectablePlayer(entry, 'ban-ip')),
  ];

  const seen = new Set<string>();
  const merged: InspectablePlayer[] = [];

  for (const bucket of buckets) {
    for (const entry of bucket) {
      if (seen.has(entry.key)) {
        continue;
      }

      seen.add(entry.key);
      merged.push(entry);
    }
  }

  return merged;
};

export default () => {
  const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
  const { clearFlashes, clearAndAddHttpError } = useFlash();
  const [search, setSearch] = useState('');
  const [selectedPlayer, setSelectedPlayer] = useState<InspectablePlayer | null>(null);

  const { data, error, mutate, isValidating } = useSWR<PlayerManagerResponse>(
    [uuid, '/playermanager'],
    (serverUuid) => getPlayers(serverUuid),
    {
      refreshInterval: 10000,
      revalidateOnFocus: false,
    }
  );

  useEffect(() => {
    if (!error) {
      clearFlashes('server:playermanager');
    } else {
      clearAndAddHttpError({ key: 'server:playermanager', error });
    }
  }, [error, clearFlashes, clearAndAddHttpError]);

  const response = data || defaultResponse;
  const normalizedSearch = search.trim().toLowerCase();
  const totalEntries = useMemo(
    () =>
      response.players.list.length +
      response.ops.length +
      response.whitelist.length +
      response.bans.length +
      response.banIps.length,
    [response]
  );
  const inspectablePlayers = useMemo(() => collectInspectablePlayers(response), [response]);

  useEffect(() => {
    if (inspectablePlayers.length < 1) {
      setSelectedPlayer(null);
      return;
    }

    setSelectedPlayer((current) => {
      if (!current) {
        return inspectablePlayers[0];
      }

      return inspectablePlayers.find((entry) => entry.key === current.key) || inspectablePlayers[0];
    });
  }, [inspectablePlayers]);

  const handleSelect = useCallback((player: InspectablePlayer) => setSelectedPlayer(player), []);

    return (
        <ServerContentBlock title={'Minecraft Player Manager'} className={'content-dashboard'} css={tw`flex flex-wrap`}>
      <div css={tw`w-full`}>
        <FlashMessageRender byKey={'server:playermanager'} css={tw`mb-4`} />
      </div>
      {!data ? (
        <div css={tw`w-full`}>
          <Spinner size={'large'} centered />
        </div>
      ) : (
                <div css={tw`w-full max-w-6xl mx-auto space-y-4`}>
          <div css={tw`rounded-lg border border-neutral-800 bg-neutral-900 shadow-md px-4 py-4 md:px-5`}>
            <div css={tw`flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between`}>
              <div>
                <h2 css={tw`text-lg md:text-xl text-neutral-100 font-semibold`}>Player Manager</h2>
                <p css={tw`text-sm text-neutral-400 mt-1`}>
                  Manage online players, operators, whitelist, and bans from one panel.
                </p>
              </div>
              <div css={tw`flex flex-wrap gap-2 items-center`}>
                <span css={tw`px-3 py-1 rounded bg-neutral-800 text-neutral-200 text-xs font-medium`}>
                  Online: {response.players.players.online}/{response.players.players.max}
                </span>
                <span css={tw`px-3 py-1 rounded bg-neutral-800 text-neutral-200 text-xs font-medium`}>
                  Entries: {totalEntries}
                </span>
                <span css={tw`px-3 py-1 rounded bg-neutral-800 text-neutral-200 text-xs font-medium`}>
                  Profiles: {inspectablePlayers.length}
                </span>
                <span css={tw`px-3 py-1 rounded bg-neutral-800 text-neutral-300 text-xs font-medium`}>
                  Auto refresh: 10s
                </span>
                <Button
                  size={'xsmall'}
                  isSecondary
                  isLoading={isValidating && !!data}
                  css={tw`normal-case tracking-normal`}
                  onClick={() => mutate()}
                >
                  Refresh
                </Button>
              </div>
            </div>
            <div css={tw`mt-4`}>
              <Input
                type={'text'}
                value={search}
                onChange={(event) => setSearch(event.currentTarget.value)}
                placeholder={'Filter players, UUIDs, and IPs...'}
                aria-label={'Filter playermanager entries'}
                css={tw`text-sm`}
              />
            </div>
          </div>

          <div css={tw`grid grid-cols-1 2xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)] gap-4 items-start`}>
            <div css={tw`order-2 2xl:order-1 space-y-4`}>
              <PlayerList
                players={response.players}
                search={normalizedSearch}
                selectedKey={selectedPlayer?.key || null}
                onSelect={handleSelect}
                onUpdate={() => mutate()}
              />
              <div css={tw`grid grid-cols-1 xl:grid-cols-2 gap-4`}>
                <OpList
                  players={response.ops}
                  search={normalizedSearch}
                  selectedKey={selectedPlayer?.key || null}
                  onSelect={handleSelect}
                  onUpdate={() => mutate()}
                />
                <WhiteList
                  players={response.whitelist}
                  search={normalizedSearch}
                  selectedKey={selectedPlayer?.key || null}
                  onSelect={handleSelect}
                  onUpdate={() => mutate()}
                />
                <Bans
                  players={response.bans}
                  search={normalizedSearch}
                  selectedKey={selectedPlayer?.key || null}
                  onSelect={handleSelect}
                  onUpdate={() => mutate()}
                />
                <BanIps
                  players={response.banIps}
                  search={normalizedSearch}
                  selectedKey={selectedPlayer?.key || null}
                  onSelect={handleSelect}
                  onUpdate={() => mutate()}
                />
              </div>
            </div>
            <div css={tw`order-1 2xl:order-2`}>
              <PlayerInspector selected={selectedPlayer} />
            </div>
          </div>
        </div>
      )}
    </ServerContentBlock>
  );
};
