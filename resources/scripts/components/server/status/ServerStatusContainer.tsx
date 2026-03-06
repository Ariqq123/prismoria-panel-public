import React, { useMemo } from 'react';
import tw from 'twin.macro';
import styled, { keyframes } from 'styled-components/macro';
import useSWR from 'swr';
import Button from '@/components/elements/Button';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import { ServerContext } from '@/state/server';
import getMinecraftStatus, { MinecraftServerStatusResult } from '@/api/server/getMinecraftStatus';

type LookupTarget = {
    host: string;
    port?: number;
    address: string;
    source: 'allocation-alias' | 'allocation-ip' | 'sftp';
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
    ${tw`flex flex-wrap gap-2`};
`;

const isUsableHost = (host: string): boolean => {
    const value = host.trim();

    return value !== '' && value !== '0.0.0.0' && value !== '::';
};

const parseHostAndPort = (input: string): { host: string; port?: number } => {
    const value = input.trim();

    if (!value) {
        return { host: '' };
    }

    const withoutProtocol = value.replace(/^https?:\/\//i, '').split('/')[0].trim();

    if (!withoutProtocol) {
        return { host: '' };
    }

    if (withoutProtocol.startsWith('[')) {
        const endIndex = withoutProtocol.indexOf(']');
        if (endIndex !== -1) {
            const host = withoutProtocol.slice(1, endIndex);
            const portPart = withoutProtocol.slice(endIndex + 1);
            if (portPart.startsWith(':')) {
                const parsedPort = Number.parseInt(portPart.slice(1), 10);
                if (Number.isInteger(parsedPort) && parsedPort > 0 && parsedPort <= 65535) {
                    return { host, port: parsedPort };
                }
            }

            return { host };
        }
    }

    const firstColon = withoutProtocol.indexOf(':');
    const lastColon = withoutProtocol.lastIndexOf(':');

    if (firstColon !== -1 && firstColon === lastColon) {
        const hostPart = withoutProtocol.slice(0, firstColon).trim();
        const portPart = withoutProtocol.slice(firstColon + 1).trim();
        const parsedPort = Number.parseInt(portPart, 10);

        if (hostPart && Number.isInteger(parsedPort) && parsedPort > 0 && parsedPort <= 65535) {
            return { host: hostPart, port: parsedPort };
        }
    }

    return { host: withoutProtocol };
};

const formatSource = (source: LookupTarget['source']): string => {
    switch (source) {
        case 'allocation-alias':
            return 'Primary allocation alias';
        case 'allocation-ip':
            return 'Primary allocation IP';
        default:
            return 'SFTP host fallback';
    }
};

const getLookupTarget = (data: any): LookupTarget | null => {
    if (!data) {
        return null;
    }

    const allocations = Array.isArray(data.allocations) ? data.allocations : [];
    const defaultAllocation = allocations.find((allocation: any) => allocation?.isDefault) ?? allocations[0];

    const allocationAlias = defaultAllocation?.alias ? String(defaultAllocation.alias).trim() : '';
    const allocationIp = defaultAllocation?.ip ? String(defaultAllocation.ip).trim() : '';
    const allocationPort =
        typeof defaultAllocation?.port === 'number' && Number.isInteger(defaultAllocation.port)
            ? defaultAllocation.port
            : undefined;

    const allocationAliasParsed = parseHostAndPort(allocationAlias);
    if (isUsableHost(allocationAliasParsed.host)) {
        const port = allocationAliasParsed.port ?? allocationPort;

        return {
            host: allocationAliasParsed.host,
            port,
            address: port ? `${allocationAliasParsed.host}:${port}` : allocationAliasParsed.host,
            source: 'allocation-alias',
        };
    }

    const allocationIpParsed = parseHostAndPort(allocationIp);
    if (isUsableHost(allocationIpParsed.host)) {
        const port = allocationIpParsed.port ?? allocationPort;

        return {
            host: allocationIpParsed.host,
            port,
            address: port ? `${allocationIpParsed.host}:${port}` : allocationIpParsed.host,
            source: 'allocation-ip',
        };
    }

    const sftpIp = data?.sftpDetails?.ip ? String(data.sftpDetails.ip).trim() : '';
    const sftpParsed = parseHostAndPort(sftpIp);
    if (isUsableHost(sftpParsed.host)) {
        const port = sftpParsed.port ?? allocationPort;

        return {
            host: sftpParsed.host,
            port,
            address: port ? `${sftpParsed.host}:${port}` : sftpParsed.host,
            source: 'sftp',
        };
    }

    return null;
};

const valueOrFallback = (value: React.ReactNode, fallback = 'Unavailable') => {
    if (value === null || value === undefined || value === '') {
        return fallback;
    }

    return value;
};

const renderMetric = (label: string, value: React.ReactNode) => (
    <div css={tw`rounded-lg border border-neutral-700 bg-neutral-900/70 p-3`}>
        <p css={tw`text-[10px] uppercase tracking-wider text-neutral-400 mb-1`}>{label}</p>
        <p css={tw`text-sm text-neutral-100 break-words`}>{valueOrFallback(value)}</p>
    </div>
);

export default () => {
    const serverName = ServerContext.useStoreState((state) => state.server.data?.name || 'Server');
    const server = ServerContext.useStoreState((state) => state.server.data);

    const lookupTarget = useMemo(() => getLookupTarget(server), [server]);

    const { data, error, isValidating, mutate } = useSWR<MinecraftServerStatusResult>(
        lookupTarget ? ['minecraft-status', lookupTarget.host, lookupTarget.port || null] : null,
        (_, host, port) => getMinecraftStatus(String(host), typeof port === 'number' ? port : undefined),
        {
            refreshInterval: 60000,
            revalidateOnFocus: false,
            dedupingInterval: 10000,
        }
    );

    const loading = !!lookupTarget && !data && !error;

    const motd = data?.data?.motd?.clean?.filter((line) => typeof line === 'string' && line.trim().length > 0) ?? [];
    const playerList = data?.data?.players?.list?.filter((name) => typeof name === 'string' && name.trim().length > 0) ?? [];
    const pluginList = data?.data?.plugins?.names?.filter((name) => typeof name === 'string' && name.trim().length > 0) ?? [];

    const statusBadge = data?.data?.online ? (
        <span css={tw`inline-flex items-center gap-2 rounded-full border border-green-500/40 bg-green-500/20 px-3 py-1 text-xs text-green-200`}>
            <span css={tw`inline-block h-2 w-2 rounded-full bg-green-300`} /> Online
        </span>
    ) : (
        <span css={tw`inline-flex items-center gap-2 rounded-full border border-red-500/40 bg-red-500/20 px-3 py-1 text-xs text-red-200`}>
            <span css={tw`inline-block h-2 w-2 rounded-full bg-red-300`} /> Offline
        </span>
    );

    return (
        <ServerContentBlock title={'Status'}>
            <MainLayout>
                <MagicCard>
                    <ShineBorder />
                    <div css={tw`relative z-10 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between`}>
                        <div css={tw`space-y-1`}>
                            <HeroTitle>Minecraft Server Status</HeroTitle>
                            <p css={tw`text-sm text-neutral-300`}>
                                Pantau status, player online, MOTD, plugin, dan metadata server langsung dari panel.
                            </p>
                        </div>
                        <ActionDock>
                            <Button size={'small'} isSecondary onClick={() => mutate()} isLoading={isValidating && !!data}>
                                Refresh
                            </Button>
                        </ActionDock>
                    </div>
                </MagicCard>

                {!lookupTarget ? (
                    <MagicCard>
                        <div css={tw`relative z-10 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200`}>
                            Unable to detect a valid server address for this instance.
                        </div>
                    </MagicCard>
                ) : loading ? (
                    <MagicCard>
                        <Spinner size={'large'} centered />
                    </MagicCard>
                ) : error ? (
                    <MagicCard>
                        <div css={tw`relative z-10 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-200`}>
                            Failed to load Minecraft status. {String((error as Error)?.message || '')}
                        </div>
                    </MagicCard>
                ) : data ? (
                    <div css={tw`space-y-4`}>
                        <div css={tw`grid grid-cols-1 xl:grid-cols-3 gap-4`}>
                            <MagicCard $interactive css={tw`xl:col-span-1`}>
                                <div css={tw`relative z-10 flex items-start gap-4`}>
                                    <div
                                        css={tw`h-16 w-16 rounded-lg overflow-hidden border border-neutral-700 bg-neutral-800 flex items-center justify-center text-sm text-neutral-400`}
                                    >
                                        {data.data.icon ? (
                                            <img src={data.data.icon} alt={`${serverName} icon`} css={tw`h-full w-full object-cover`} />
                                        ) : (
                                            <span>{serverName.charAt(0).toUpperCase()}</span>
                                        )}
                                    </div>
                                    <div css={tw`min-w-0 flex-1`}>
                                        <h2 css={tw`text-lg font-semibold text-neutral-100 truncate`}>{serverName}</h2>
                                        <p css={tw`text-sm text-neutral-400 break-words mt-1`}>{lookupTarget.address}</p>
                                        <div css={tw`mt-3`}>{statusBadge}</div>
                                    </div>
                                </div>
                                <div css={tw`relative z-10 mt-4 text-xs text-neutral-400`}>
                                    Lookup source: <span css={tw`text-neutral-300`}>{formatSource(lookupTarget.source)}</span>
                                </div>
                                <div css={tw`relative z-10 mt-2 text-xs text-neutral-400`}>
                                    Last update:{' '}
                                    <span css={tw`text-neutral-300`}>
                                        {new Date(data.fetchedAt).toLocaleTimeString()}
                                    </span>
                                </div>
                            </MagicCard>

                            <MagicCard $interactive css={tw`xl:col-span-2`}>
                                <div css={tw`relative z-10 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3`}>
                                    {renderMetric(
                                        'Players',
                                        typeof data.data.players?.online === 'number' && typeof data.data.players?.max === 'number'
                                            ? `${data.data.players.online}/${data.data.players.max}`
                                            : 'Unavailable'
                                    )}
                                    {renderMetric('Mode', data.mode === 'bedrock' ? 'Bedrock' : 'Java')}
                                    {renderMetric('Version', data.data.version || data.data.protocol_name)}
                                    {renderMetric('Hostname', data.data.hostname || lookupTarget.host)}
                                    {renderMetric('Resolved IP', data.data.ip)}
                                    {renderMetric('Port', data.data.port || lookupTarget.port)}
                                    {renderMetric('Software', data.data.software)}
                                    {renderMetric('Map', data.data.map)}
                                    {renderMetric(
                                        'SRV Record',
                                        typeof data.data.debug?.srv === 'boolean'
                                            ? data.data.debug.srv
                                                ? 'Detected'
                                                : 'Not detected'
                                            : 'Unavailable'
                                    )}
                                </div>
                            </MagicCard>
                        </div>

                        <div css={tw`grid grid-cols-1 xl:grid-cols-2 gap-4`}>
                            <MagicCard $interactive>
                                <div css={tw`relative z-10`}>
                                    <h3 css={tw`text-sm uppercase tracking-wide text-neutral-300 mb-3`}>MOTD</h3>
                                    {motd.length > 0 ? (
                                        <div css={tw`space-y-1 text-sm text-neutral-100`}>
                                            {motd.map((line, index) => (
                                                <p key={`${line}-${index}`} css={tw`break-words`}>
                                                    {line}
                                                </p>
                                            ))}
                                        </div>
                                    ) : (
                                        <p css={tw`text-sm text-neutral-400`}>No MOTD returned by the server.</p>
                                    )}
                                </div>
                            </MagicCard>

                            <MagicCard $interactive>
                                <div css={tw`relative z-10`}>
                                    <h3 css={tw`text-sm uppercase tracking-wide text-neutral-300 mb-3`}>Online Players</h3>
                                    {playerList.length > 0 ? (
                                        <div css={tw`flex flex-wrap gap-2`}>
                                            {playerList.slice(0, 64).map((player) => (
                                                <span
                                                    key={player}
                                                    css={tw`inline-flex items-center rounded bg-neutral-800 border border-neutral-700 px-2 py-1 text-xs text-neutral-200`}
                                                >
                                                    {player}
                                                </span>
                                            ))}
                                        </div>
                                    ) : (
                                        <p css={tw`text-sm text-neutral-400`}>No player list data available.</p>
                                    )}
                                </div>
                            </MagicCard>
                        </div>

                        <MagicCard $interactive>
                            <div css={tw`relative z-10`}>
                                <h3 css={tw`text-sm uppercase tracking-wide text-neutral-300 mb-3`}>Plugins</h3>
                                {pluginList.length > 0 ? (
                                    <div css={tw`flex flex-wrap gap-2`}>
                                        {pluginList.slice(0, 96).map((plugin) => (
                                            <span
                                                key={plugin}
                                                css={tw`inline-flex items-center rounded bg-neutral-800 border border-neutral-700 px-2 py-1 text-xs text-neutral-200`}
                                            >
                                                {plugin}
                                            </span>
                                        ))}
                                    </div>
                                ) : (
                                    <p css={tw`text-sm text-neutral-400`}>No plugin metadata returned by query.</p>
                                )}
                            </div>
                        </MagicCard>
                    </div>
                ) : null}
            </MainLayout>
        </ServerContentBlock>
    );
};
