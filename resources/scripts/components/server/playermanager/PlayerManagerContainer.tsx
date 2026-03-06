import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import tw from 'twin.macro';
import styled, { keyframes } from 'styled-components/macro';
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
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faUsers, faLayerGroup, faUserCheck } from '@fortawesome/free-solid-svg-icons';

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

const borderFlow = keyframes`
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
`;

const auroraText = keyframes`
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
`;

const MainLayout = styled.div`
    ${tw`w-full max-w-6xl mx-auto space-y-4`};
`;

const MagicCard = styled.div<{ $interactive?: boolean }>`
    ${tw`relative overflow-hidden rounded-xl border border-neutral-700 p-4 md:p-5`};
    background: linear-gradient(140deg, rgba(17, 24, 39, 0.93) 0%, rgba(9, 13, 20, 0.96) 56%, rgba(16, 18, 24, 0.98) 100%);
    box-shadow: 0 16px 36px rgba(0, 0, 0, 0.3);
    transition: transform 240ms cubic-bezier(0.22, 1, 0.36, 1), border-color 220ms ease, box-shadow 220ms ease;

    &::before {
        content: '';
        position: absolute;
        inset: -35% -12%;
        pointer-events: none;
        background: radial-gradient(circle at top right, rgba(248, 113, 113, 0.16), transparent 58%);
    }

    ${({ $interactive }) =>
        $interactive
            ? `
        &:hover {
            transform: translateY(-2px);
            border-color: rgba(248, 113, 113, 0.46);
            box-shadow: 0 20px 44px rgba(0, 0, 0, 0.38);
        }
    `
            : ''}
`;

const ShineBorder = styled.div`
    position: absolute;
    inset: 0;
    border-radius: inherit;
    pointer-events: none;
    border: 1px solid transparent;
    background: linear-gradient(
            125deg,
            rgba(248, 113, 113, 0.44),
            rgba(251, 191, 36, 0.32),
            rgba(96, 165, 250, 0.28),
            rgba(248, 113, 113, 0.44)
        )
        border-box;
    background-size: 250% 250%;
    animation: ${borderFlow} 6s ease infinite;
    -webkit-mask: linear-gradient(#fff 0 0) padding-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
`;

const HeroTitle = styled.h2`
    ${tw`text-lg md:text-xl font-semibold tracking-wide`};
    background: linear-gradient(96deg, #fde68a, #fca5a5, #93c5fd, #fca5a5);
    background-size: 220% 220%;
    animation: ${auroraText} 7.2s ease infinite;
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
`;

const ActionDock = styled.div`
    ${tw`flex flex-wrap gap-2 items-center`};
`;

const InfoChip = styled.div`
    ${tw`rounded-lg border border-neutral-700 bg-black/30 px-3 py-2`};
`;

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
            <MainLayout>
                <FlashMessageRender byKey={'server:playermanager'} css={tw`mb-4`} />
                {!data ? (
                    <MagicCard>
                        <Spinner size={'large'} centered />
                    </MagicCard>
                ) : (
                    <>
                        <MagicCard>
                            <ShineBorder />
                            <div css={tw`relative z-10 space-y-4`}>
                                <div css={tw`flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between`}>
                                    <div css={tw`space-y-1`}>
                                        <HeroTitle>Player Manager</HeroTitle>
                                        <p css={tw`text-sm text-neutral-300`}>
                                            Kelola player online, operator, whitelist, ban player, dan ban IP dari satu halaman.
                                        </p>
                                    </div>
                                    <ActionDock>
                                        <Button
                                            size={'xsmall'}
                                            isSecondary
                                            isLoading={isValidating && !!data}
                                            css={tw`normal-case tracking-normal`}
                                            onClick={() => mutate()}
                                        >
                                            Refresh
                                        </Button>
                                    </ActionDock>
                                </div>
                                <div css={tw`grid grid-cols-1 sm:grid-cols-4 gap-2`}>
                                    <InfoChip>
                                        <p css={tw`text-[11px] uppercase tracking-widest text-neutral-400 mb-1`}>Online</p>
                                        <p css={tw`text-sm text-neutral-100 truncate`}>
                                            <FontAwesomeIcon icon={faUsers} css={tw`mr-2 text-red-300`} />
                                            {response.players.players.online}/{response.players.players.max}
                                        </p>
                                    </InfoChip>
                                    <InfoChip>
                                        <p css={tw`text-[11px] uppercase tracking-widest text-neutral-400 mb-1`}>Entries</p>
                                        <p css={tw`text-sm text-neutral-100 truncate`}>
                                            <FontAwesomeIcon icon={faLayerGroup} css={tw`mr-2 text-red-300`} />
                                            {totalEntries}
                                        </p>
                                    </InfoChip>
                                    <InfoChip>
                                        <p css={tw`text-[11px] uppercase tracking-widest text-neutral-400 mb-1`}>Profiles</p>
                                        <p css={tw`text-sm text-neutral-100 truncate`}>
                                            <FontAwesomeIcon icon={faUserCheck} css={tw`mr-2 text-red-300`} />
                                            {inspectablePlayers.length}
                                        </p>
                                    </InfoChip>
                                    <InfoChip>
                                        <p css={tw`text-[11px] uppercase tracking-widest text-neutral-400 mb-1`}>Auto Refresh</p>
                                        <p css={tw`text-sm text-neutral-100`}>10s</p>
                                    </InfoChip>
                                </div>
                                <div>
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
                        </MagicCard>

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
                    </>
                )}
            </MainLayout>
    </ServerContentBlock>
  );
};
