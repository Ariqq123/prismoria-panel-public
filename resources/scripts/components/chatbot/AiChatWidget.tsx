import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useStoreState } from 'easy-peasy';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCommentDots, faPaperPlane, faTimes } from '@fortawesome/free-solid-svg-icons';
import { httpErrorToHuman } from '@/api/http';
import getServer from '@/api/server/getServer';
import {
    AssistantHistoryEntry,
    AssistantProvider,
    AssistantRequestContext,
    AssistantServerContext,
    AssistantServerSource,
    sendAssistantChat,
} from '@/api/account/assistant';

type MessageRole = 'assistant' | 'user';

interface Message {
    id: string;
    role: MessageRole;
    content: string;
}

interface AiChatWidgetProps {
    hideTrigger?: boolean;
}

const WELCOME_MESSAGE: Message = {
    id: 'welcome-assistant',
    role: 'assistant',
    content: 'Hi. I am your panel assistant. Ask about server setup, errors, routes, or module behavior.',
};

const PROVIDER_OPTIONS: Array<{ value: AssistantProvider; label: string }> = [
    { value: 'openai', label: 'OpenAI' },
    { value: 'groq', label: 'Groq' },
    { value: 'gemini', label: 'Gemini' },
];
const SERVER_ROUTE_PATTERN = /^\/server\/([^/]+)/;
const MOBILE_BREAKPOINT = 1150;
const MOBILE_VIEWPORT_QUERY = `(max-width: ${MOBILE_BREAKPOINT}px)`;

const toHistoryPayload = (messages: Message[]): AssistantHistoryEntry[] => {
    return messages
        .filter((message) => message.role === 'assistant' || message.role === 'user')
        .slice(-12)
        .map((message) => ({
            role: message.role,
            content: message.content,
        }));
};

const newMessageId = (): string => `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

const resolveServerIdentifierFromPathname = (pathname: string): string => {
    const match = pathname.match(SERVER_ROUTE_PATTERN);
    if (!match || !match[1]) {
        return '';
    }

    try {
        return decodeURIComponent(match[1]);
    } catch {
        return match[1];
    }
};

const inferServerSourceFromIdentifier = (identifier: string): AssistantServerSource => {
    return identifier.startsWith('external:') ? 'external' : 'local';
};

const buildRequestContext = (pathname: string, activeServer?: AssistantServerContext): AssistantRequestContext => {
    const routePath = pathname.trim() || '/';
    const routeServerIdentifier = resolveServerIdentifierFromPathname(pathname);

    if (!routeServerIdentifier) {
        return {
            routePath,
        };
    }

    const serverContext: AssistantServerContext = {
        identifier: routeServerIdentifier,
        source:
            activeServer && activeServer.identifier === routeServerIdentifier
                ? activeServer.source || inferServerSourceFromIdentifier(routeServerIdentifier)
                : inferServerSourceFromIdentifier(routeServerIdentifier),
    };

    if (activeServer && activeServer.identifier === routeServerIdentifier) {
        if (activeServer.name) {
            serverContext.name = activeServer.name;
        }
        if (activeServer.uuid) {
            serverContext.uuid = activeServer.uuid;
        }
        if (activeServer.externalPanelName) {
            serverContext.externalPanelName = activeServer.externalPanelName;
        }
        if (activeServer.externalPanelUrl) {
            serverContext.externalPanelUrl = activeServer.externalPanelUrl;
        }
        if (activeServer.externalServerIdentifier) {
            serverContext.externalServerIdentifier = activeServer.externalServerIdentifier;
        }
    }

    return {
        routePath,
        server: serverContext,
    };
};

export default ({ hideTrigger = false }: AiChatWidgetProps) => {
    const location = useLocation();
    const activeRouteServerIdentifier = useMemo(
        () => resolveServerIdentifierFromPathname(location.pathname),
        [location.pathname]
    );
    const isAuthenticated = useStoreState((state) => !!state.user.data?.uuid);
    const isServerRoute = useMemo(() => /^\/server(?:\/|$)/.test(location.pathname), [location.pathname]);
    const [isOpen, setOpen] = useState(false);
    const [isSending, setSending] = useState(false);
    const [draft, setDraft] = useState('');
    const [messages, setMessages] = useState<Message[]>([WELCOME_MESSAGE]);
    const [error, setError] = useState('');
    const [model, setModel] = useState<string | undefined>(undefined);
    const [provider, setProvider] = useState<AssistantProvider>('groq');
    const [activeServer, setActiveServer] = useState<AssistantServerContext | undefined>(undefined);
    const [isServerSidebarCollapsed, setServerSidebarCollapsed] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return window.localStorage.getItem('server_sidebar_collapsed') === '1';
    });
    const [isMobileViewport, setMobileViewport] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return window.innerWidth <= MOBILE_BREAKPOINT;
    });
    const messagesContainerRef = useRef<HTMLDivElement | null>(null);

    const shouldHide = useMemo(() => {
        if (!isAuthenticated || location.pathname.startsWith('/auth')) {
            return true;
        }

        return false;
    }, [isAuthenticated, location.pathname]);
    const chatLeftOffset = useMemo(() => {
        if (!isServerRoute || isMobileViewport) {
            return '1.5rem';
        }

        return isServerSidebarCollapsed
            ? 'calc(var(--sidebar-collapsed-size) + 1.5rem)'
            : 'calc(var(--sidebar-size) + 1.5rem)';
    }, [isServerRoute, isMobileViewport, isServerSidebarCollapsed]);

    useEffect(() => {
        if (!isAuthenticated) {
            setActiveServer(undefined);

            return;
        }

        const identifier = activeRouteServerIdentifier;
        if (!identifier) {
            setActiveServer(undefined);

            return;
        }

        const fallbackSource = inferServerSourceFromIdentifier(identifier);
        setActiveServer((current) => {
            if (!current || current.identifier !== identifier) {
                return {
                    identifier,
                    source: fallbackSource,
                };
            }

            return current.source
                ? current
                : {
                      ...current,
                      source: fallbackSource,
                  };
        });

        let isCancelled = false;
        getServer(identifier)
            .then(([server]) => {
                if (isCancelled) {
                    return;
                }

                setActiveServer({
                    identifier,
                    name: typeof server.name === 'string' && server.name.trim().length > 0 ? server.name.trim() : undefined,
                    uuid: typeof server.uuid === 'string' && server.uuid.trim().length > 0 ? server.uuid.trim() : undefined,
                    source: server.source === 'external' ? 'external' : 'local',
                    externalPanelName:
                        typeof server.externalPanelName === 'string' && server.externalPanelName.trim().length > 0
                            ? server.externalPanelName.trim()
                            : undefined,
                    externalPanelUrl:
                        typeof server.externalPanelUrl === 'string' && server.externalPanelUrl.trim().length > 0
                            ? server.externalPanelUrl.trim()
                            : undefined,
                    externalServerIdentifier:
                        typeof server.externalServerIdentifier === 'string' && server.externalServerIdentifier.trim().length > 0
                            ? server.externalServerIdentifier.trim()
                            : undefined,
                });
            })
            .catch(() => undefined);

        return () => {
            isCancelled = true;
        };
    }, [isAuthenticated, activeRouteServerIdentifier]);

    useEffect(() => {
        const handleServerSidebarState = (event: Event) => {
            const detail = (
                event as CustomEvent<{
                    desktopCollapsed?: boolean;
                }>
            ).detail;
            if (!detail) {
                return;
            }

            if (typeof detail.desktopCollapsed === 'boolean') {
                setServerSidebarCollapsed(detail.desktopCollapsed);
            }
        };

        window.addEventListener('server-sidebar:state', handleServerSidebarState);

        return () => {
            window.removeEventListener('server-sidebar:state', handleServerSidebarState);
        };
    }, []);

    useEffect(() => {
        const mediaQuery = window.matchMedia(MOBILE_VIEWPORT_QUERY);
        const updateViewport = () => setMobileViewport(mediaQuery.matches);

        updateViewport();

        if (typeof mediaQuery.addEventListener === 'function') {
            mediaQuery.addEventListener('change', updateViewport);

            return () => {
                mediaQuery.removeEventListener('change', updateViewport);
            };
        }

        mediaQuery.addListener(updateViewport);

        return () => {
            mediaQuery.removeListener(updateViewport);
        };
    }, []);

    useEffect(() => {
        const handleToggle = () => setOpen((current) => !current);
        const handleOpen = () => setOpen(true);
        const handleClose = () => setOpen(false);

        window.addEventListener('ai-chat:toggle', handleToggle);
        window.addEventListener('ai-chat:open', handleOpen);
        window.addEventListener('ai-chat:close', handleClose);

        return () => {
            window.removeEventListener('ai-chat:toggle', handleToggle);
            window.removeEventListener('ai-chat:open', handleOpen);
            window.removeEventListener('ai-chat:close', handleClose);
        };
    }, []);

    if (shouldHide) {
        return null;
    }

    const chatPanelStyle = hideTrigger ? { left: '50%', transform: 'translateX(-50%)' } : { left: chatLeftOffset };

    const scrollToBottom = () => {
        window.requestAnimationFrame(() => {
            if (!messagesContainerRef.current) {
                return;
            }

            messagesContainerRef.current.scrollTop = messagesContainerRef.current.scrollHeight;
        });
    };

    const submit = async () => {
        const text = draft.trim();
        if (text === '' || isSending) {
            return;
        }

        setError('');
        setDraft('');
        setSending(true);

        const userMessage: Message = {
            id: newMessageId(),
            role: 'user',
            content: text,
        };
        const nextMessages = [...messages, userMessage];
        setMessages(nextMessages);
        scrollToBottom();

        try {
            const reply = await sendAssistantChat(
                text,
                toHistoryPayload(nextMessages),
                provider,
                buildRequestContext(location.pathname, activeServer)
            );
            const content = (reply.message || '').trim();
            if (content === '') {
                throw new Error('Assistant returned an empty response.');
            }

            setModel(reply.model);
            if (reply.provider) {
                setProvider(reply.provider);
            }
            setMessages((previous) => [
                ...previous,
                {
                    id: newMessageId(),
                    role: 'assistant',
                    content,
                },
            ]);
        } catch (caught) {
            const detail = httpErrorToHuman(caught);
            setError(detail);
        } finally {
            setSending(false);
            scrollToBottom();
        }
    };

    return (
        <>
            {!hideTrigger && (
                <button
                    type={'button'}
                    onClick={() => setOpen((value) => !value)}
                    className={
                        'fixed bottom-6 z-[70] inline-flex items-center gap-2 rounded-xl border border-red-500/50 bg-neutral-900/90 px-4 py-2.5 text-sm font-semibold text-red-200 shadow-xl transition-all hover:border-red-400 hover:text-red-100'
                    }
                    style={{ left: chatLeftOffset }}
                    aria-label={isOpen ? 'Close AI assistant' : 'Open AI assistant'}
                    aria-expanded={isOpen}
                >
                    <FontAwesomeIcon icon={isOpen ? faTimes : faCommentDots} />
                    <span>{isOpen ? 'Close Chat' : 'AI Chat'}</span>
                </button>
            )}

            {isOpen && (
                <div
                    className={'fixed bottom-24 z-[70] w-[min(92vw,27rem)] rounded-2xl border border-neutral-700 bg-neutral-900/95 shadow-2xl transition-all'}
                    style={chatPanelStyle}
                >
                    <div className={'flex items-center justify-between border-b border-neutral-700 px-4 py-3'}>
                        <div className={'min-w-0'}>
                            <p className={'truncate text-sm font-semibold text-neutral-100'}>AI Assistant</p>
                            <p className={'truncate text-xs text-neutral-400'}>{model ? `Model: ${model}` : 'Select provider and chat'}</p>
                        </div>
                        <div className={'ml-3 flex items-center gap-2'}>
                            <select
                                value={provider}
                                disabled={isSending}
                                onChange={(event) => {
                                    setProvider(event.currentTarget.value as AssistantProvider);
                                    setError('');
                                }}
                                className={'rounded-md border border-neutral-600 bg-neutral-800 px-2 py-1 text-xs text-neutral-100 outline-none focus:border-red-400'}
                                aria-label={'AI provider'}
                            >
                                {PROVIDER_OPTIONS.map((item) => (
                                    <option key={item.value} value={item.value}>
                                        {item.label}
                                    </option>
                                ))}
                            </select>
                            <button
                                type={'button'}
                                onClick={() => setOpen(false)}
                                className={'rounded-md p-1.5 text-neutral-400 transition-colors hover:bg-neutral-800 hover:text-neutral-100'}
                                aria-label={'Close AI assistant'}
                            >
                                <FontAwesomeIcon icon={faTimes} />
                            </button>
                        </div>
                    </div>

                    <div ref={messagesContainerRef} className={'max-h-[55vh] min-h-[18rem] space-y-3 overflow-y-auto px-4 py-4'}>
                        {messages.map((message) => (
                            <div
                                key={message.id}
                                className={message.role === 'user' ? 'flex justify-end' : 'flex justify-start'}
                            >
                                <div
                                    className={
                                        message.role === 'user'
                                            ? 'max-w-[86%] whitespace-pre-wrap break-words rounded-xl border border-red-500/40 bg-red-500/15 px-3 py-2 text-sm text-red-100'
                                            : 'max-w-[86%] whitespace-pre-wrap break-words rounded-xl border border-neutral-700 bg-neutral-800/80 px-3 py-2 text-sm text-neutral-100'
                                    }
                                >
                                    {message.content}
                                </div>
                            </div>
                        ))}
                        {isSending && (
                            <div className={'flex justify-start'}>
                                <div className={'rounded-xl border border-neutral-700 bg-neutral-800/70 px-3 py-2 text-sm text-neutral-300'}>
                                    Thinking...
                                </div>
                            </div>
                        )}
                    </div>

                    {error !== '' && (
                        <div className={'border-t border-red-600/30 bg-red-950/20 px-4 py-2 text-xs text-red-300'}>
                            {error}
                        </div>
                    )}

                    <div className={'border-t border-neutral-700 px-3 py-3'}>
                        <div className={'flex items-end gap-2'}>
                            <textarea
                                value={draft}
                                disabled={isSending}
                                onChange={(event) => setDraft(event.currentTarget.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter' && !event.shiftKey) {
                                        event.preventDefault();
                                        submit();
                                    }
                                }}
                                rows={2}
                                placeholder={'Ask AI assistant...'}
                                className={
                                    'min-h-[2.75rem] max-h-40 w-full resize-y rounded-lg border border-neutral-600 bg-neutral-800 px-3 py-2 text-sm text-neutral-100 outline-none transition-colors placeholder:text-neutral-500 focus:border-red-400'
                                }
                            />
                            <button
                                type={'button'}
                                onClick={submit}
                                disabled={isSending || draft.trim() === ''}
                                className={
                                    'inline-flex h-11 w-11 flex-none items-center justify-center rounded-lg border border-red-500/50 bg-red-500/20 text-red-100 transition-all hover:border-red-400 hover:bg-red-500/30 disabled:cursor-not-allowed disabled:opacity-45'
                                }
                                aria-label={'Send message'}
                            >
                                <FontAwesomeIcon icon={faPaperPlane} />
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
};
