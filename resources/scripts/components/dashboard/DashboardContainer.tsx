import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faBars, faBan, faLayerGroup, faPlay, faStop, faSyncAlt } from '@fortawesome/free-solid-svg-icons';
import { Server } from '@/api/server/getServer';
import getServers from '@/api/getServers';
import ServerRow from '@/components/dashboard/ServerRow';
import Spinner from '@/components/elements/Spinner';
import PageContentBlock from '@/components/elements/PageContentBlock';
import useFlash from '@/plugins/useFlash';
import { useStoreState } from 'easy-peasy';
import { usePersistedState } from '@/plugins/usePersistedState';
import Switch from '@/components/elements/Switch';
import tw from 'twin.macro';
import useSWR from 'swr';
import { PaginatedResult } from '@/api/http';
import Pagination from '@/components/elements/Pagination';
import { useLocation } from 'react-router-dom';
import styled, { css, keyframes } from 'styled-components/macro';
import AnimatedGradientText from '@/components/elements/AnimatedGradientText';

import BeforeContent from '@blueprint/components/Dashboard/Serverlist/BeforeContent';
import AfterContent from '@blueprint/components/Dashboard/Serverlist/AfterContent';

type ServerListDisplayMode = 'list' | 'boxed';
type ServerStatusFilter = 'all' | 'online' | 'offline' | 'suspended';
const serverSearchTextCache = new WeakMap<Server, string>();
const ONLINE_SERVER_STATES = new Set(['running', 'starting', 'stopping']);
const OFFLINE_SERVER_STATES = new Set(['offline', 'stopped', 'install_failed', 'reinstall_failed']);
const MAX_SERVER_ORDER_IDS = 2000;
const STATUS_FILTER_OPTIONS: Array<{ value: ServerStatusFilter; label: string; icon: typeof faLayerGroup }> = [
    { value: 'all', label: 'All statuses', icon: faLayerGroup },
    { value: 'online', label: 'Running / Online', icon: faPlay },
    { value: 'offline', label: 'Offline', icon: faStop },
    { value: 'suspended', label: 'Suspended', icon: faBan },
];

const normalizeUrlLikeValue = (value: string): string => value.replace(/^https?:\/\//, '').replace(/\/+$/, '');

const buildServerSearchText = (server: Server): string => {
    const cached = serverSearchTextCache.get(server);
    if (cached) {
        return cached;
    }

    const defaultAllocations = server.allocations.reduce<string>((result, allocation) => {
        if (!allocation.isDefault) {
            return result;
        }

        const next = `${allocation.alias || allocation.ip}:${allocation.port}`;

        return result ? `${result} ${next}` : next;
    }, '');
    const externalPanelUrl = (server.externalPanelUrl || '').toLowerCase();
    const normalizedExternalPanelUrl = normalizeUrlLikeValue(externalPanelUrl);
    const haystack = [
        server.name,
        server.id,
        server.uuid,
        server.description || '',
        defaultAllocations,
        server.externalPanelName || '',
        externalPanelUrl,
        normalizedExternalPanelUrl,
        server.externalServerIdentifier || '',
    ]
        .join(' ')
        .toLowerCase();

    serverSearchTextCache.set(server, haystack);

    return haystack;
};

const normalizeServerOrderIds = (value: unknown): string[] => {
    if (!Array.isArray(value)) {
        return [];
    }

    const seen = new Set<string>();
    const ids: string[] = [];

    for (const item of value) {
        const id = typeof item === 'string' ? item.trim() : '';
        if (id === '' || seen.has(id)) {
            continue;
        }

        seen.add(id);
        ids.push(id);

        if (ids.length >= MAX_SERVER_ORDER_IDS) {
            break;
        }
    }

    return ids;
};

const seedServerOrderIds = (existingOrder: string[], visibleServerIds: string[]): string[] => {
    if (visibleServerIds.length < 1) {
        return existingOrder;
    }

    const seen = new Set(existingOrder);
    const seeded = existingOrder.slice();

    for (const id of visibleServerIds) {
        if (seen.has(id)) {
            continue;
        }

        seen.add(id);
        seeded.push(id);
    }

    return seeded.slice(0, MAX_SERVER_ORDER_IDS);
};

const reorderServerIds = (order: string[], draggedId: string, targetId: string): string[] => {
    if (draggedId === targetId) {
        return order;
    }

    const draggedIndex = order.indexOf(draggedId);
    const targetIndex = order.indexOf(targetId);
    if (draggedIndex < 0 || targetIndex < 0) {
        return order;
    }

    const next = order.slice();
    [next[draggedIndex], next[targetIndex]] = [next[targetIndex], next[draggedIndex]];

    return next.slice(0, MAX_SERVER_ORDER_IDS);
};

const applyServerOrder = (items: Server[], order: string[]): Server[] => {
    if (items.length < 2 || order.length < 1) {
        return items;
    }

    const orderIndex = new Map<string, number>();
    for (let index = 0; index < order.length; index++) {
        orderIndex.set(order[index], index);
    }

    return items
        .map((server, index) => ({ server, index }))
        .sort((left, right) => {
            const leftOrder = orderIndex.get(left.server.id);
            const rightOrder = orderIndex.get(right.server.id);

            if (leftOrder === undefined && rightOrder === undefined) {
                return left.index - right.index;
            }

            if (leftOrder === undefined) {
                return 1;
            }

            if (rightOrder === undefined) {
                return -1;
            }

            return leftOrder - rightOrder;
        })
        .map((entry) => entry.server);
};

const serverMatchesStatusFilter = (server: Server, filter: ServerStatusFilter): boolean => {
    if (filter === 'all') {
        return true;
    }

    const status = typeof server.status === 'string' ? server.status.toLowerCase() : '';
    const isExternalServer = server.source === 'external';
    if (filter === 'suspended') {
        return status === 'suspended';
    }

    if (filter === 'online') {
        // Local server list payloads often have null status for normal servers.
        if (status === '') {
            return !isExternalServer;
        }

        return ONLINE_SERVER_STATES.has(status);
    }

    if (filter === 'offline') {
        if (status === '') {
            return isExternalServer;
        }

        return OFFLINE_SERVER_STATES.has(status);
    }

    return true;
};

const slideInFromRight = keyframes`
    from {
        opacity: 0.08;
        transform: translate3d(20px, 0, 0);
    }
    to {
        opacity: 1;
        transform: translate3d(0, 0, 0);
    }
`;

const slideInFromLeft = keyframes`
    from {
        opacity: 0.08;
        transform: translate3d(-20px, 0, 0);
    }
    to {
        opacity: 1;
        transform: translate3d(0, 0, 0);
    }
`;

const AnimatedModeSwap = styled.div<{ $direction: 'left' | 'right' }>`
    animation: ${({ $direction }) => ($direction === 'right' ? slideInFromRight : slideInFromLeft)}
        520ms cubic-bezier(0.16, 1, 0.3, 1);
    animation-fill-mode: both;
    will-change: transform, opacity;
    backface-visibility: hidden;

    @media (prefers-reduced-motion: reduce) {
        animation-duration: 1ms;
    }
`;

const draggingServerCardStyles = css`
    opacity: 0.45;
    transform: scale(0.992);
`;

const idleServerCardStyles = css`
    opacity: 1;
    transform: none;
`;

const dropTargetServerCardStyles = css`
    outline: 2px dashed rgba(248, 113, 113, 0.85);
    outline-offset: 2px;
`;

const resetButtonShimmer = keyframes`
    0% {
        background-position: -180% 0;
    }
    100% {
        background-position: 180% 0;
    }
`;

const MagicResetButton = styled.button`
    ${tw`relative inline-flex h-10 items-center justify-center gap-2 overflow-hidden rounded-full px-4 text-xs font-semibold tracking-wide`};
    border: 1px solid var(--panel-dock-button-border);
    background: var(--panel-dock-button-bg);
    color: var(--panel-dock-button-text);
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.26);
    backdrop-filter: blur(8px);
    transition: transform 180ms cubic-bezier(0.22, 1, 0.36, 1), border-color 180ms ease, color 180ms ease;

    &::before {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: inherit;
        background: linear-gradient(
            110deg,
            rgba(248, 113, 113, 0) 32%,
            rgba(248, 113, 113, 0.52) 50%,
            rgba(248, 113, 113, 0) 68%
        );
        background-size: 220% 100%;
        animation: ${resetButtonShimmer} 2.8s linear infinite;
        pointer-events: none;
    }

    > span {
        ${tw`relative z-10 inline-flex items-center gap-2`};
    }

    &:hover,
    &:focus-visible {
        border-color: rgba(248, 113, 113, 0.82);
        color: rgb(254 202 202);
        transform: translateY(-1px);
        outline: none;
    }

    &:active {
        transform: translateY(0);
    }
`;

const DraggableServerCard = styled.div<{ $isDragging: boolean; $isDropTarget: boolean }>`
    cursor: grab;
    border-radius: 0.75rem;
    user-select: none;
    transition:
        transform 180ms cubic-bezier(0.22, 1, 0.36, 1),
        outline-color 140ms ease,
        opacity 140ms ease;
    will-change: transform, opacity;

    ${({ $isDragging }) => ($isDragging ? draggingServerCardStyles : idleServerCardStyles)}
    ${({ $isDropTarget, $isDragging }) => ($isDropTarget && !$isDragging ? dropTargetServerCardStyles : css``)}

    &:active {
        cursor: grabbing;
    }

    @media (prefers-reduced-motion: reduce) {
        transition-duration: 1ms;
    }
`;

export default () => {
    const { search } = useLocation();
    const defaultPage = Number(new URLSearchParams(search).get('page') || '1');

    const [page, setPage] = useState(!isNaN(defaultPage) && defaultPage > 0 ? defaultPage : 1);
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const uuid = useStoreState((state) => state.user.data!.uuid);
    const rootAdmin = useStoreState((state) => state.user.data!.rootAdmin);
    const [showOnlyAdmin, setShowOnlyAdmin] = usePersistedState(`${uuid}:show_all_servers`, false);
    const [serverListDisplayMode, setServerListDisplayMode] = usePersistedState<ServerListDisplayMode>(
        `${uuid}:dashboard_server_list_mode`,
        'boxed'
    );
    const [dashboardSearch, setDashboardSearch] = usePersistedState<string>(`${uuid}:dashboard_server_search`, '');
    const [serverStatusFilter, setServerStatusFilter] = usePersistedState<ServerStatusFilter>(
        `${uuid}:dashboard_server_status_filter`,
        'all'
    );
    const [serverOrderIds, setServerOrderIds] = usePersistedState<string[]>(`${uuid}:dashboard_server_order`, []);
    const normalizedServerOrder = useMemo(() => normalizeServerOrderIds(serverOrderIds), [serverOrderIds]);
    const [draggedServerId, setDraggedServerId] = useState<string | null>(null);
    const [dragOverServerId, setDragOverServerId] = useState<string | null>(null);
    const dragPreviewElement = useRef<HTMLDivElement | null>(null);
    const serverStatusFilterValue: ServerStatusFilter =
        serverStatusFilter === 'online' ||
        serverStatusFilter === 'offline' ||
        serverStatusFilter === 'suspended' ||
        serverStatusFilter === 'all'
            ? serverStatusFilter
            : 'all';
    const dashboardSearchValue = typeof dashboardSearch === 'string' ? dashboardSearch : '';
    const normalizedSearch = useMemo(() => dashboardSearchValue.trim().toLowerCase(), [dashboardSearchValue]);
    const [animationDirection, setAnimationDirection] = useState<'left' | 'right'>('right');

    const { data: servers, error } = useSWR<PaginatedResult<Server>>(
        ['/api/client/servers', showOnlyAdmin && rootAdmin, page],
        () => getServers({ page, type: showOnlyAdmin && rootAdmin ? 'admin' : undefined }),
        {
            revalidateOnFocus: false,
            revalidateOnReconnect: true,
            dedupingInterval: 15000,
        }
    );

    useEffect(() => {
        if (!servers) return;
        if (servers.pagination.currentPage > 1 && !servers.items.length) {
            setPage(1);
        }
    }, [servers?.pagination.currentPage]);

    useEffect(() => {
        // Don't use react-router to handle changing this part of the URL, otherwise it
        // triggers a needless re-render. We just want to track this in the URL incase the
        // user refreshes the page.
        window.history.replaceState(null, document.title, `/${page <= 1 ? '' : `?page=${page}`}`);
    }, [page]);

    useEffect(() => {
        if (error) clearAndAddHttpError({ key: 'dashboard', error });
        if (!error) clearFlashes('dashboard');
    }, [error]);

    const displayMode: ServerListDisplayMode = serverListDisplayMode === 'list' ? 'list' : 'boxed';
    const previousDisplayModeRef = React.useRef<ServerListDisplayMode>(displayMode);

    useEffect(() => {
        if (previousDisplayModeRef.current === displayMode) return;

        setAnimationDirection(previousDisplayModeRef.current === 'boxed' && displayMode === 'list' ? 'right' : 'left');
        previousDisplayModeRef.current = displayMode;
    }, [displayMode]);

    const filterServerList = useMemo(
        () => (items: Server[]): Server[] => {
            return items.filter((server) => {
                if (!serverMatchesStatusFilter(server, serverStatusFilterValue)) {
                    return false;
                }

                if (normalizedSearch.length < 1) {
                    return true;
                }

                const normalizedUrlSearch = normalizeUrlLikeValue(normalizedSearch);
                const haystack = buildServerSearchText(server);

                return (
                    haystack.includes(normalizedSearch) ||
                    (normalizedUrlSearch.length > 0 && haystack.includes(normalizedUrlSearch))
                );
            });
        },
        [normalizedSearch, serverStatusFilterValue]
    );

    const clearDragPreviewElement = useCallback(() => {
        const element = dragPreviewElement.current;
        if (element?.parentNode) {
            element.parentNode.removeChild(element);
        }

        dragPreviewElement.current = null;
    }, []);

    const handleServerDragStart = useCallback((event: React.DragEvent<HTMLDivElement>, serverId: string) => {
        setDraggedServerId(serverId);
        setDragOverServerId(serverId);
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', serverId);

        const source = event.currentTarget;
        const sourceRect = source.getBoundingClientRect();
        const preview = source.cloneNode(true) as HTMLDivElement;

        preview.style.position = 'fixed';
        preview.style.left = '-10000px';
        preview.style.top = '-10000px';
        preview.style.pointerEvents = 'none';
        preview.style.width = `${sourceRect.width}px`;
        preview.style.maxWidth = `${sourceRect.width}px`;
        preview.style.margin = '0';
        preview.style.opacity = '0.98';
        preview.style.transform = 'none';
        preview.style.boxSizing = 'border-box';
        preview.style.zIndex = '9999';
        preview.removeAttribute('draggable');

        document.body.appendChild(preview);
        dragPreviewElement.current = preview;

        const offsetX = Math.min(Math.max(event.clientX - sourceRect.left, 12), Math.max(sourceRect.width - 12, 12));
        const offsetY = Math.min(Math.max(event.clientY - sourceRect.top, 12), Math.max(sourceRect.height - 12, 12));

        event.dataTransfer.setDragImage(preview, offsetX, offsetY);

        window.setTimeout(clearDragPreviewElement, 0);
    }, [clearDragPreviewElement]);

    const handleServerDragEnd = useCallback(() => {
        setDraggedServerId(null);
        setDragOverServerId(null);
        clearDragPreviewElement();
    }, [clearDragPreviewElement]);

    const handleServerDragOver = useCallback((event: React.DragEvent<HTMLDivElement>, serverId: string) => {
        if (!draggedServerId || draggedServerId === serverId) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        if (dragOverServerId !== serverId) {
            setDragOverServerId(serverId);
        }
    }, [dragOverServerId, draggedServerId]);

    const handleServerDrop = useCallback(
        (event: React.DragEvent<HTMLDivElement>, targetServerId: string, visibleServerIds: string[]) => {
            event.preventDefault();

            const draggedId = draggedServerId || event.dataTransfer.getData('text/plain');
            if (!draggedId || draggedId === targetServerId) {
                setDragOverServerId(null);
                return;
            }

            setServerOrderIds((previousOrder) => {
                const normalized = normalizeServerOrderIds(previousOrder);
                const seeded = seedServerOrderIds(normalized, visibleServerIds);

                return reorderServerIds(seeded, draggedId, targetServerId);
            });
            setDragOverServerId(null);
            clearDragPreviewElement();
        },
        [clearDragPreviewElement, draggedServerId, setServerOrderIds]
    );

    return (
        <PageContentBlock title={'Prismoria Network'} showFlashKey={'dashboard'}>
            <BeforeContent />
            <div css={tw`mb-3 flex justify-center`}>
                <h1 css={tw`text-center text-3xl font-semibold tracking-tight`}>
                    <AnimatedGradientText speed={1.15} colorFrom={'#fecaca'} colorTo={'#ef4444'}>
                        Prismoria Network
                    </AnimatedGradientText>
                </h1>
            </div>
            <div css={tw`mb-4 space-y-3`}>
                <div css={tw`flex justify-center`}>
                    <div css={tw`inline-flex items-center gap-2 rounded-xl border border-neutral-700 bg-neutral-900/80 p-1`}>
                        <button
                            type={'button'}
                            css={[
                                tw`inline-flex min-w-[8.5rem] items-center justify-center gap-2 rounded-lg border px-5 py-2.5 text-sm font-semibold transition-colors`,
                                displayMode === 'list'
                                    ? tw`border-red-500 bg-red-500/20 text-red-200`
                                    : tw`border-neutral-600 bg-neutral-800 text-neutral-300 hover:border-neutral-500 hover:text-neutral-100`,
                            ]}
                            onClick={() => setServerListDisplayMode('list')}
                            aria-pressed={displayMode === 'list'}
                        >
                            <FontAwesomeIcon icon={faLayerGroup} />
                            Boxed
                        </button>
                        <button
                            type={'button'}
                            css={[
                                tw`inline-flex min-w-[8.5rem] items-center justify-center gap-2 rounded-lg border px-5 py-2.5 text-sm font-semibold transition-colors`,
                                displayMode === 'boxed'
                                    ? tw`border-red-500 bg-red-500/20 text-red-200`
                                    : tw`border-neutral-600 bg-neutral-800 text-neutral-300 hover:border-neutral-500 hover:text-neutral-100`,
                            ]}
                            onClick={() => setServerListDisplayMode('boxed')}
                            aria-pressed={displayMode === 'boxed'}
                        >
                            <FontAwesomeIcon icon={faBars} />
                            List
                        </button>
                    </div>
                </div>
                {rootAdmin && (
                    <div css={tw`flex items-center justify-center`}>
                        <p css={tw`uppercase text-xs text-neutral-400 mr-2`}>
                            {showOnlyAdmin ? "Showing others' servers" : 'Showing your servers'}
                        </p>
                        <Switch
                            name={'show_all_servers'}
                            defaultChecked={showOnlyAdmin}
                            onChange={() => setShowOnlyAdmin((s) => !s)}
                        />
                    </div>
                )}
                <div css={tw`mx-auto w-full max-w-3xl px-2`}>
                    <div css={tw`flex justify-center`}>
                        <div
                            css={tw`inline-flex items-center gap-2 rounded-full border border-neutral-600/80 bg-neutral-900/75 px-3 py-2 shadow-2xl backdrop-blur-md`}
                        >
                            {normalizedSearch.length > 0 && (
                                <button
                                    type={'button'}
                                    onClick={() => setDashboardSearch('')}
                                    css={tw`inline-flex h-10 items-center justify-center rounded-full border border-neutral-500/60 bg-neutral-800/80 px-4 text-xs uppercase tracking-wide text-neutral-200 transition-all duration-150 hover:border-red-400/80 hover:text-red-200`}
                                >
                                    Clear Search
                                </button>
                            )}
                            <div
                                id={'dashboard-server-status-filter'}
                                role={'group'}
                                aria-label={'Filter servers by status'}
                                css={tw`inline-flex items-center gap-2`}
                            >
                                {STATUS_FILTER_OPTIONS.map((option) => (
                                    <button
                                        key={option.value}
                                        type={'button'}
                                        title={option.label}
                                        aria-label={option.label}
                                        onClick={() => setServerStatusFilter(option.value)}
                                        css={[
                                            tw`inline-flex h-10 w-10 items-center justify-center rounded-full border text-sm transition-all duration-150`,
                                            serverStatusFilterValue === option.value
                                                ? tw`border-red-400 bg-red-500/20 text-red-200`
                                                : tw`border-neutral-500/60 bg-neutral-800/80 text-neutral-300 hover:border-red-400/80 hover:text-red-200`,
                                        ]}
                                    >
                                        <FontAwesomeIcon icon={option.icon} />
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>
                    <div css={tw`mt-2 flex items-center justify-between`}>
                        <div />
                        <MagicResetButton
                            type={'button'}
                            onClick={() => setServerOrderIds([])}
                        >
                            <span>
                                <FontAwesomeIcon icon={faSyncAlt} />
                                <span>Reset</span>
                            </span>
                        </MagicResetButton>
                    </div>
                </div>
            </div>
            {!servers ? (
                <Spinner centered size={'large'} />
            ) : (
                <Pagination data={servers} onPageSelect={setPage}>
                    {({ items }) => {
                        const filteredItems = filterServerList(items);
                        const orderedItems = applyServerOrder(filteredItems, normalizedServerOrder);
                        const visibleServerIds = orderedItems.map((server) => server.id);

                        return orderedItems.length > 0 ? (
                            <div css={tw`mx-auto w-full max-w-[92rem]`}>
                                <AnimatedModeSwap key={displayMode} $direction={animationDirection}>
                                    {displayMode === 'list' ? (
                                        <div css={tw`grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 justify-items-center`}>
                                            {orderedItems.map((server) => (
                                                <DraggableServerCard
                                                    key={server.id}
                                                    draggable
                                                    onDragStart={(event) => handleServerDragStart(event, server.id)}
                                                    onDragOver={(event) => handleServerDragOver(event, server.id)}
                                                    onDrop={(event) => handleServerDrop(event, server.id, visibleServerIds)}
                                                    onDragEnd={handleServerDragEnd}
                                                    $isDragging={draggedServerId === server.id}
                                                    $isDropTarget={
                                                        dragOverServerId === server.id && draggedServerId !== server.id
                                                    }
                                                    css={[
                                                        tw`w-full max-w-[26rem]`,
                                                    ]}
                                                >
                                                    <ServerRow
                                                        server={server}
                                                        displayMode={displayMode}
                                                    />
                                                </DraggableServerCard>
                                            ))}
                                        </div>
                                    ) : (
                                        <>
                                            {orderedItems.map((server, index) => (
                                                <DraggableServerCard
                                                    key={server.id}
                                                    draggable
                                                    onDragStart={(event) => handleServerDragStart(event, server.id)}
                                                    onDragOver={(event) => handleServerDragOver(event, server.id)}
                                                    onDrop={(event) => handleServerDrop(event, server.id, visibleServerIds)}
                                                    onDragEnd={handleServerDragEnd}
                                                    $isDragging={draggedServerId === server.id}
                                                    $isDropTarget={
                                                        dragOverServerId === server.id && draggedServerId !== server.id
                                                    }
                                                    css={[
                                                        index > 0 ? tw`mt-3` : undefined,
                                                    ]}
                                                >
                                                    <ServerRow
                                                        server={server}
                                                        displayMode={displayMode}
                                                    />
                                                </DraggableServerCard>
                                            ))}
                                        </>
                                    )}
                                </AnimatedModeSwap>
                            </div>
                        ) : (
                            <p css={tw`text-center text-sm text-neutral-400`}>
                                {normalizedSearch.length > 0
                                    ? 'No servers matched your search on this page.'
                                    : serverStatusFilterValue !== 'all'
                                    ? 'No servers matched the selected status on this page.'
                                    : showOnlyAdmin
                                    ? 'There are no other servers to display.'
                                    : 'There are no servers associated with your account.'}
                            </p>
                        );
                    }}
                </Pagination>
            )}
            <AfterContent />
        </PageContentBlock>
    );
};
