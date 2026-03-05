import React, { memo, useCallback, useEffect, useRef, useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faEthernet, faHdd, faMemory, faMicrochip, faServer } from '@fortawesome/free-solid-svg-icons';
import { Link } from 'react-router-dom';
import { Server } from '@/api/server/getServer';
import getServerResourceUsage, { ServerPowerState, ServerStats } from '@/api/server/getServerResourceUsage';
import { bytesToString, ip, mbToBytes } from '@/lib/formatters';
import tw from 'twin.macro';
import GreyRowBox from '@/components/elements/GreyRowBox';
import Spinner from '@/components/elements/Spinner';
import styled from 'styled-components/macro';
import isEqual from 'react-fast-compare';

import BeforeEntryName from '@blueprint/components/Dashboard/Serverlist/ServerRow/BeforeEntryName';
import AfterEntryName from '@blueprint/components/Dashboard/Serverlist/ServerRow/AfterEntryName';
import BeforeEntryDescription from '@blueprint/components/Dashboard/Serverlist/ServerRow/BeforeEntryDescription';
import AfterEntryDescription from '@blueprint/components/Dashboard/Serverlist/ServerRow/AfterEntryDescription';
import ResourceLimits from '@blueprint/components/Dashboard/Serverlist/ServerRow/ResourceLimits';

// Determines if the current value is in an alarm threshold so we can show it in red rather
// than the more faded default style.
const isAlarmState = (current: number, limit: number): boolean => limit > 0 && current / (limit * 1024 * 1024) >= 0.9;

const Icon = memo(
    styled(FontAwesomeIcon)<{ $alarm: boolean }>`
        ${(props) => (props.$alarm ? tw`text-red-400` : tw`text-neutral-500`)};
    `,
    isEqual
);

const IconDescription = styled.p<{ $alarm: boolean }>`
    ${tw`text-sm ml-2`};
    ${(props) => (props.$alarm ? tw`text-white` : tw`text-neutral-400`)};
`;

const StatusIndicatorBox = styled(GreyRowBox)<{ $status: ServerPowerState | undefined }>`
    ${tw`grid grid-cols-12 gap-4 relative`};
    content-visibility: auto;
    contain-intrinsic-size: 300px;

    &.server-list-row--mode-boxed {
        ${tw`w-full rounded-xl border-neutral-600 bg-neutral-900/80 px-5 py-5`};
        min-height: 9.25rem;
    }

    &.server-list-row--mode-list {
        ${tw`w-full flex flex-col gap-3 rounded-xl border-neutral-600 bg-neutral-900/80 p-5`};
        aspect-ratio: 1 / 1;
        min-height: 18rem;
        max-width: 26rem;
    }

    &.server-list-row--mode-list .icon {
        ${tw`w-11 h-11 p-0 rounded-full bg-neutral-900/95 text-neutral-200`};
    }

    &.server-list-row--mode-list .status-bar {
        ${tw`h-1 w-auto left-2 right-2 bottom-2 top-auto m-0 rounded-full opacity-80`};
    }

    @media (max-width: 640px) {
        &.server-list-row--mode-list {
            aspect-ratio: 4 / 3;
            min-height: 15rem;
            max-width: none;
        }
    }

    & .status-bar {
        ${tw`w-2 bg-red-500 absolute right-0 z-20 rounded-full m-1 opacity-50 transition-all duration-150`};
        height: calc(100% - 0.5rem);

        ${({ $status }) =>
            !$status || $status === 'offline'
                ? tw`bg-red-500`
                : $status === 'running'
                ? tw`bg-green-500`
                : tw`bg-yellow-500`};
    }

    &:hover .status-bar {
        ${tw`opacity-75`};
    }
`;

type Timer = ReturnType<typeof setInterval>;
type PollDelayTimer = ReturnType<typeof setTimeout>;
type ServerRowDisplayMode = 'boxed' | 'list';
const DEFAULT_POLL_INTERVAL_MS = 30000;
const EXTERNAL_POLL_INTERVAL_MS = 60000;
const VIEWPORT_OBSERVER_ROOT_MARGIN = '320px 0px';
let isServerRouterPrefetched = false;

const pollJitterMs = (seed: string): number => {
    let hash = 0;

    for (let index = 0; index < seed.length; index++) {
        hash = (hash << 5) - hash + seed.charCodeAt(index);
        hash |= 0;
    }

    return Math.abs(hash % 900);
};

const prefetchServerRouterChunk = () => {
    if (isServerRouterPrefetched) {
        return;
    }

    isServerRouterPrefetched = true;
    import(/* webpackChunkName: "server" */ '@/routers/ServerRouter').catch(() => {
        isServerRouterPrefetched = false;
    });
};

type ServerRowProps = {
    server: Server;
    className?: string;
    displayMode?: ServerRowDisplayMode;
};

const ServerRow = ({
    server,
    className,
    displayMode = 'boxed',
}: ServerRowProps) => {
    const interval = useRef<Timer | null>(null);
    const initialPollTimer = useRef<PollDelayTimer | null>(null);
    const isMounted = useRef(true);
    const requestController = useRef<AbortController | null>(null);
    const [isInViewport, setIsInViewport] = useState<boolean>(() => {
        if (typeof window === 'undefined') {
            return true;
        }

        return !('IntersectionObserver' in window);
    });
    const [isPageVisible, setPageVisible] = useState<boolean>(() => {
        if (typeof document === 'undefined') {
            return true;
        }

        return document.visibilityState !== 'hidden';
    });
    const [isSuspended, setIsSuspended] = useState(server.status === 'suspended');
    const [stats, setStats] = useState<ServerStats | null>(null);

    const getStats = useCallback(async () => {
        requestController.current?.abort();

        const controller = new AbortController();
        requestController.current = controller;

        try {
            const data = await getServerResourceUsage(server.uuid, controller.signal);
            if (isMounted.current) {
                setStats(data);
            }
        } catch (error: any) {
            if (error?.code === 'ERR_CANCELED') {
                return;
            }

            if (isMounted.current) {
                console.error(error);
            }
        }
    }, [server.uuid]);

    useEffect(
        () => () => {
            isMounted.current = false;
            requestController.current?.abort();

            if (interval.current) {
                clearInterval(interval.current);
                interval.current = null;
            }

            if (initialPollTimer.current) {
                clearTimeout(initialPollTimer.current);
                initialPollTimer.current = null;
            }
        },
        []
    );

    useEffect(() => {
        const onVisibilityChange = () => {
            setPageVisible(document.visibilityState !== 'hidden');
        };

        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            document.removeEventListener('visibilitychange', onVisibilityChange);
        };
    }, []);

    useEffect(() => {
        if (typeof window === 'undefined' || !('IntersectionObserver' in window)) {
            setIsInViewport(true);
            return;
        }

        const rowElement = document.querySelector<HTMLElement>(`[data-server-uuid="${server.uuid}"]`);
        if (!rowElement) {
            setIsInViewport(true);
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                const entry = entries[0];
                if (!entry) {
                    return;
                }

                setIsInViewport(entry.isIntersecting || entry.intersectionRatio > 0);
            },
            {
                rootMargin: VIEWPORT_OBSERVER_ROOT_MARGIN,
                threshold: 0.01,
            }
        );

        observer.observe(rowElement);

        return () => {
            observer.disconnect();
        };
    }, [server.id, server.uuid]);

    useEffect(() => {
        setIsSuspended(stats?.isSuspended || server.status === 'suspended');
    }, [stats?.isSuspended, server.status]);

    useEffect(() => {
        // Don't waste a HTTP request if there is nothing important to show to the user because
        // the server is suspended.
        if (isSuspended || !isInViewport || !isPageVisible) return;

        const pollInterval = server.source === 'external' ? EXTERNAL_POLL_INTERVAL_MS : DEFAULT_POLL_INTERVAL_MS;
        initialPollTimer.current = setTimeout(() => {
            if (!isMounted.current) {
                return;
            }

            getStats();
            interval.current = setInterval(getStats, pollInterval);
            initialPollTimer.current = null;
        }, pollJitterMs(server.id));

        return () => {
            requestController.current?.abort();

            if (initialPollTimer.current) {
                clearTimeout(initialPollTimer.current);
                initialPollTimer.current = null;
            }

            if (interval.current) {
                clearInterval(interval.current);
                interval.current = null;
            }
        };
    }, [isSuspended, isInViewport, isPageVisible, getStats, server.id, server.source]);

    const alarms = { cpu: false, memory: false, disk: false };
    if (stats) {
        alarms.cpu = server.limits.cpu === 0 ? false : stats.cpuUsagePercent >= server.limits.cpu * 0.9;
        alarms.memory = isAlarmState(stats.memoryUsageInBytes, server.limits.memory);
        alarms.disk = server.limits.disk === 0 ? false : isAlarmState(stats.diskUsageInBytes, server.limits.disk);
    }

    const diskLimit = server.limits.disk !== 0 ? bytesToString(mbToBytes(server.limits.disk)) : 'Unlimited';
    const memoryLimit = server.limits.memory !== 0 ? bytesToString(mbToBytes(server.limits.memory)) : 'Unlimited';
    const cpuLimit = server.limits.cpu !== 0 ? server.limits.cpu + ' %' : 'Unlimited';
    const isListMode = displayMode === 'list';
    const defaultAllocation = server.allocations
        .filter((alloc) => alloc.isDefault)
        .map((allocation) => `${allocation.alias || ip(allocation.ip)}:${allocation.port}`)
        .join(', ');
    const stateText = isSuspended
        ? server.status === 'suspended'
            ? 'Suspended'
            : 'Connection Error'
        : server.isTransferring
        ? 'Transferring'
        : server.status === 'installing'
        ? 'Installing'
        : server.status === 'restoring_backup'
        ? 'Restoring Backup'
        : stats?.status === 'running'
        ? 'Running'
        : stats?.status === 'starting'
        ? 'Starting'
        : stats?.status === 'stopping'
        ? 'Stopping'
        : stats
        ? 'Offline'
        : 'Loading';
    const rowClassName = [
        'server-list-row',
        isListMode ? 'server-list-row--mode-list' : 'server-list-row--mode-boxed',
        className,
    ]
        .filter(Boolean)
        .join(' ');

    if (isListMode) {
        return (
            <StatusIndicatorBox
                as={Link}
                to={`/server/${server.id}`}
                draggable={false}
                className={rowClassName}
                onMouseEnter={prefetchServerRouterChunk}
                onFocus={prefetchServerRouterChunk}
                data-server-identifier={server.id}
                data-server-uuid={server.uuid}
                data-server-egg-id={server.BlueprintFramework?.eggId ?? ''}
                $status={stats?.status}
            >
                <div css={tw`flex items-start justify-between gap-3 min-w-0`}>
                    <div css={tw`flex items-start gap-3 min-w-0`}>
                        <div className={'icon'}>
                            <FontAwesomeIcon icon={faServer} />
                        </div>
                        <div css={tw`min-w-0`}>
                            <BeforeEntryName />
                            <p className={'server-row-name'} css={tw`text-lg leading-tight break-words line-clamp-2`}>
                                {server.name}
                            </p>
                            <AfterEntryName />
                        </div>
                    </div>
                    <span
                        css={[
                            tw`inline-flex min-w-[6.25rem] justify-center flex-shrink-0 rounded px-2 py-1 text-xs uppercase tracking-wide`,
                            stateText === 'Running'
                                ? tw`bg-green-600/80 text-green-50`
                                : stateText === 'Loading'
                                ? tw`bg-neutral-600/80 text-neutral-100`
                                : stateText === 'Suspended' || stateText === 'Connection Error'
                                ? tw`bg-red-600/90 text-red-50`
                                : tw`bg-yellow-600/90 text-yellow-50`,
                        ]}
                    >
                        {stateText}
                    </span>
                </div>

                {!!server.description && (
                    <div css={tw`min-w-0`}>
                        <BeforeEntryDescription />
                        <p className={'server-row-description'} css={tw`text-xs text-neutral-200/95 break-words line-clamp-2`}>
                            {server.description}
                        </p>
                        <AfterEntryDescription />
                    </div>
                )}

                <div css={tw`mt-auto space-y-2`}>
                    <div css={tw`flex items-center gap-2 text-xs text-neutral-100`}>
                        <FontAwesomeIcon icon={faEthernet} css={tw`text-neutral-300`} />
                        <span css={tw`truncate`}>{defaultAllocation || 'No allocation'}</span>
                    </div>
                    {stats && !isSuspended && (
                        <div css={tw`grid grid-cols-3 gap-1 text-xs text-neutral-100`}>
                            <div css={tw`flex items-center gap-1`}>
                                <Icon icon={faMicrochip} $alarm={alarms.cpu} />
                                <span>{stats.cpuUsagePercent.toFixed(0)}%</span>
                            </div>
                            <div css={tw`flex items-center gap-1`}>
                                <Icon icon={faMemory} $alarm={alarms.memory} />
                                <span>{bytesToString(stats.memoryUsageInBytes)}</span>
                            </div>
                            <div css={tw`flex items-center gap-1`}>
                                <Icon icon={faHdd} $alarm={alarms.disk} />
                                <span>{bytesToString(stats.diskUsageInBytes)}</span>
                            </div>
                        </div>
                    )}
                </div>
                <div className={'status-bar'} />
            </StatusIndicatorBox>
        );
    }

    return (
        <StatusIndicatorBox
            as={Link}
            to={`/server/${server.id}`}
            draggable={false}
            className={rowClassName}
            onMouseEnter={prefetchServerRouterChunk}
            onFocus={prefetchServerRouterChunk}
            data-server-identifier={server.id}
            data-server-uuid={server.uuid}
            data-server-egg-id={server.BlueprintFramework?.eggId ?? ''}
            $status={stats?.status}
        >
            <div css={tw`flex items-center col-span-12 sm:col-span-5 lg:col-span-6`}>
                <div className={'icon mr-4'}>
                    <FontAwesomeIcon icon={faServer} />
                </div>
                <div css={tw`min-w-0`}>
                    <BeforeEntryName />
                    <p className={'server-row-name'} css={tw`text-lg break-words`}>
                        {server.name}
                    </p>
                    <AfterEntryName />
                    {!!server.description && (
                        <div>
                            <BeforeEntryDescription />
                            <p className={'server-row-description'} css={tw`text-sm text-neutral-300 break-words line-clamp-2`}>
                                {server.description}
                            </p>
                            <AfterEntryDescription />
                        </div>
                    )}
                </div>
            </div>
            <div css={tw`flex-1 ml-4 lg:block lg:col-span-2 hidden`}>
                <div css={tw`flex justify-center`}>
                    <FontAwesomeIcon icon={faEthernet} css={tw`text-neutral-500`} />
                    <p css={tw`text-sm text-neutral-400 ml-2`}>
                        {server.allocations
                            .filter((alloc) => alloc.isDefault)
                            .map((allocation) => (
                                <React.Fragment key={allocation.ip + allocation.port.toString()}>
                                    {allocation.alias || ip(allocation.ip)}:{allocation.port}
                                </React.Fragment>
                            ))}
                    </p>
                </div>
            </div>
            <div css={tw`hidden col-span-7 lg:col-span-4 sm:flex items-baseline justify-center min-h-[2.5rem]`}>
                {!stats || isSuspended ? (
                    isSuspended ? (
                        <div css={tw`flex-1 text-center`}>
                            <span css={tw`bg-red-500 rounded px-2 py-1 text-red-100 text-xs`}>
                                {server.status === 'suspended' ? 'Suspended' : 'Connection Error'}
                            </span>
                        </div>
                    ) : server.isTransferring || server.status ? (
                        <div css={tw`flex-1 text-center`}>
                            <span css={tw`bg-neutral-500 rounded px-2 py-1 text-neutral-100 text-xs`}>
                                {server.isTransferring
                                    ? 'Transferring'
                                    : server.status === 'installing'
                                    ? 'Installing'
                                    : server.status === 'restoring_backup'
                                    ? 'Restoring Backup'
                                    : 'Unavailable'}
                            </span>
                        </div>
                    ) : (
                        <Spinner size={'small'} />
                    )
                ) : (
                    <React.Fragment>
                        <div css={tw`flex-1 ml-4 sm:block hidden`}>
                            <div css={tw`flex justify-center`}>
                                <Icon icon={faMicrochip} $alarm={alarms.cpu} />
                                <IconDescription $alarm={alarms.cpu}>
                                    {stats.cpuUsagePercent.toFixed(2)} %
                                </IconDescription>
                            </div>
                            <p css={tw`text-xs text-neutral-600 text-center mt-1`}>of {cpuLimit}</p>
                        </div>
                        <div css={tw`flex-1 ml-4 sm:block hidden`}>
                            <div css={tw`flex justify-center`}>
                                <Icon icon={faMemory} $alarm={alarms.memory} />
                                <IconDescription $alarm={alarms.memory}>
                                    {bytesToString(stats.memoryUsageInBytes)}
                                </IconDescription>
                            </div>
                            <p css={tw`text-xs text-neutral-600 text-center mt-1`}>of {memoryLimit}</p>
                        </div>
                        <div css={tw`flex-1 ml-4 sm:block hidden`}>
                            <div css={tw`flex justify-center`}>
                                <Icon icon={faHdd} $alarm={alarms.disk} />
                                <IconDescription $alarm={alarms.disk}>
                                    {bytesToString(stats.diskUsageInBytes)}
                                </IconDescription>
                            </div>
                            <p css={tw`text-xs text-neutral-600 text-center mt-1`}>of {diskLimit}</p>
                        </div>
                        <ResourceLimits />
                    </React.Fragment>
                )}
            </div>
            <div className={'status-bar'} />
        </StatusIndicatorBox>
    );
};

const areServerRowPropsEqual = (previous: ServerRowProps, next: ServerRowProps): boolean =>
    previous.server === next.server &&
    previous.displayMode === next.displayMode &&
    previous.className === next.className;

export default memo(ServerRow, areServerRowPropsEqual);
