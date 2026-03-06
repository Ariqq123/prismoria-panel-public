import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ITerminalOptions, Terminal } from 'xterm';
import { FitAddon } from 'xterm-addon-fit';
import { SearchAddon } from 'xterm-addon-search';
import { SearchBarAddon } from 'xterm-addon-search-bar';
import { WebLinksAddon } from 'xterm-addon-web-links';
import { ScrollDownHelperAddon } from '@/plugins/XtermScrollDownHelperAddon';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import { ServerContext } from '@/state/server';
import { usePermissions } from '@/plugins/usePermissions';
import { theme as th } from 'twin.macro';
import useEventListener from '@/plugins/useEventListener';
import { debounce } from 'debounce';
import { usePersistedState } from '@/plugins/usePersistedState';
import { SocketEvent, SocketRequest } from '@/components/server/events';
import { PANEL_COLOR_MODE_UPDATED_EVENT } from '@/lib/colorMode';
import classNames from 'classnames';
import { ChevronDoubleRightIcon } from '@heroicons/react/solid';

import CommandRow from '@blueprint/components/Server/Terminal/CommandRow';

import 'xterm/css/xterm.css';
import styles from './style.module.css';

const getCssVariable = (name: string, fallback: string): string => {
    if (typeof window === 'undefined') {
        return fallback;
    }

    const value = window.getComputedStyle(document.body).getPropertyValue(name).trim();
    return value || fallback;
};

const resolveTerminalTheme = (): ITerminalOptions['theme'] => {
    const terminalBg = getCssVariable('--panel-terminal-bg', '#0f0f0f');
    const terminalFg = getCssVariable('--panel-terminal-input-text', '#f3f4f6');

    return {
        background: terminalBg,
        foreground: terminalFg,
        cursor: terminalFg,
        cursorAccent: terminalBg,
        black: terminalBg,
        red: '#E54B4B',
        green: '#9ECE58',
        yellow: '#FAED70',
        blue: '#396FE2',
        magenta: '#BB80B3',
        cyan: '#2DDAFD',
        white: '#d0d0d0',
        brightBlack: 'rgba(255, 255, 255, 0.2)',
        brightRed: '#FF5370',
        brightGreen: '#C3E88D',
        brightYellow: '#FFCB6B',
        brightBlue: '#82AAFF',
        brightMagenta: '#C792EA',
        brightCyan: '#89DDFF',
        brightWhite: terminalFg,
        selection: 'rgba(250, 240, 137, 0.3)',
    };
};

const terminalProps: ITerminalOptions = {
    disableStdin: true,
    cursorStyle: 'underline',
    allowTransparency: true,
    fontSize: 12,
    fontFamily: th('fontFamily.mono'),
    rows: 30,
    theme: resolveTerminalTheme(),
};

const STRIP_TRAILING_NEWLINE = /(?:\r\n|\r|\n)$/im;
const MAX_QUEUED_TERMINAL_LINES = 500;

export default () => {
    const TERMINAL_PRELUDE = '\u001b[1m\u001b[33mcontainer@pterodactyl~ \u001b[0m';
    const ref = useRef<HTMLDivElement>(null);
    const writeQueue = useRef<string[]>([]);
    const writeFrame = useRef<number | null>(null);
    const isSidebarTransitioningRef = useRef(false);
    const terminal = useMemo(() => new Terminal({ ...terminalProps }), []);
    const fitAddon = useMemo(() => new FitAddon(), []);
    const searchAddon = useMemo(() => new SearchAddon(), []);
    const searchBar = useMemo(() => new SearchBarAddon({ searchAddon }), [searchAddon]);
    const webLinksAddon = useMemo(() => new WebLinksAddon(), []);
    const scrollDownHelperAddon = useMemo(() => new ScrollDownHelperAddon(), []);
    const { connected, instance } = ServerContext.useStoreState((state) => state.socket);
    const [canSendCommands] = usePermissions(['control.console']);
    const serverId = ServerContext.useStoreState((state) => state.server.data!.id);
    const isTransferring = ServerContext.useStoreState((state) => state.server.data!.isTransferring);
    const [history, setHistory] = usePersistedState<string[]>(`${serverId}:command_history`, []);
    const [historyIndex, setHistoryIndex] = useState(-1);
    // SearchBarAddon has hardcoded z-index: 999 :(
    const zIndex = `
    .xterm-search-bar__addon {
        z-index: 10;
    }`;

    const flushWriteQueue = useCallback(() => {
        writeFrame.current = null;

        if (isSidebarTransitioningRef.current) {
            return;
        }

        if (!terminal.element || writeQueue.current.length === 0) {
            return;
        }

        terminal.write(writeQueue.current.join(''));
        writeQueue.current = [];
    }, [terminal]);

    const queueConsoleLine = useCallback(
        (line: string) => {
            writeQueue.current.push(line + '\r\n');

            if (writeQueue.current.length > MAX_QUEUED_TERMINAL_LINES) {
                writeQueue.current = writeQueue.current.slice(-MAX_QUEUED_TERMINAL_LINES);
            }

            if (isSidebarTransitioningRef.current) {
                return;
            }

            if (writeFrame.current === null) {
                writeFrame.current = window.requestAnimationFrame(flushWriteQueue);
            }
        },
        [flushWriteQueue]
    );

    const clearPendingWrites = useCallback(() => {
        if (writeFrame.current !== null) {
            window.cancelAnimationFrame(writeFrame.current);
            writeFrame.current = null;
        }

        writeQueue.current = [];
    }, []);

    const applyTerminalTheme = useCallback(() => {
        terminal.options.theme = resolveTerminalTheme();
    }, [terminal]);

    const handleConsoleOutput = useCallback(
        (line: string, prelude = false) =>
            queueConsoleLine((prelude ? TERMINAL_PRELUDE : '') + line.replace(STRIP_TRAILING_NEWLINE, '') + '\u001b[0m'),
        [queueConsoleLine]
    );

    const handleTransferStatus = useCallback(
        (status: string) => {
            switch (status) {
                // Sent by either the source or target node if a failure occurs.
                case 'failure':
                    queueConsoleLine(TERMINAL_PRELUDE + 'Transfer has failed.\u001b[0m');
                    return;
            }
        },
        [queueConsoleLine]
    );

    const handleDaemonErrorOutput = useCallback(
        (line: string) =>
            queueConsoleLine(TERMINAL_PRELUDE + '\u001b[1m\u001b[41m' + line.replace(STRIP_TRAILING_NEWLINE, '') + '\u001b[0m'),
        [queueConsoleLine]
    );

    const handlePowerChangeEvent = useCallback(
        (state: string) => queueConsoleLine(TERMINAL_PRELUDE + 'Server marked as ' + state + '...\u001b[0m'),
        [queueConsoleLine]
    );

    const handleCommandKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowUp') {
            const newIndex = Math.min(historyIndex + 1, history!.length - 1);

            setHistoryIndex(newIndex);
            e.currentTarget.value = history![newIndex] || '';

            // By default up arrow will also bring the cursor to the start of the line,
            // so we'll preventDefault to keep it at the end.
            e.preventDefault();
        }

        if (e.key === 'ArrowDown') {
            const newIndex = Math.max(historyIndex - 1, -1);

            setHistoryIndex(newIndex);
            e.currentTarget.value = history![newIndex] || '';
        }

        const command = e.currentTarget.value;
        if (e.key === 'Enter' && command.length > 0) {
            setHistory((prevHistory) => [command, ...prevHistory!].slice(0, 32));
            setHistoryIndex(-1);

            instance && instance.send('send command', command);
            e.currentTarget.value = '';
        }
    };

    useEffect(() => {
        const onSidebarState = (event: Event) => {
            const detail = (event as CustomEvent<{ desktopTransitioning?: boolean }>).detail;
            const isTransitioning = Boolean(detail?.desktopTransitioning);
            const wasTransitioning = isSidebarTransitioningRef.current;

            isSidebarTransitioningRef.current = isTransitioning;

            if (isTransitioning && writeFrame.current !== null) {
                window.cancelAnimationFrame(writeFrame.current);
                writeFrame.current = null;
            }

            if (wasTransitioning && !isTransitioning) {
                if (writeQueue.current.length > 0 && writeFrame.current === null) {
                    writeFrame.current = window.requestAnimationFrame(flushWriteQueue);
                }

                if (terminal.element) {
                    window.requestAnimationFrame(() => fitAddon.fit());
                }
            }
        };

        window.addEventListener('server-sidebar:state', onSidebarState);

        return () => {
            window.removeEventListener('server-sidebar:state', onSidebarState);
        };
    }, [flushWriteQueue, terminal, fitAddon]);

    useEffect(() => {
        if (connected && ref.current && !terminal.element) {
            terminal.loadAddon(fitAddon);
            terminal.loadAddon(searchAddon);
            terminal.loadAddon(searchBar);
            terminal.loadAddon(webLinksAddon);
            terminal.loadAddon(scrollDownHelperAddon);

            terminal.open(ref.current);
            applyTerminalTheme();
            fitAddon.fit();
            searchBar.addNewStyle(zIndex);

            // Add support for capturing keys
            terminal.attachCustomKeyEventHandler((e: KeyboardEvent) => {
                if ((e.ctrlKey || e.metaKey) && e.key === 'c') {
                    document.execCommand('copy');
                    return false;
                } else if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                    e.preventDefault();
                    searchBar.show();
                    return false;
                } else if (e.key === 'Escape') {
                    searchBar.hidden();
                }
                return true;
            });
        }
    }, [terminal, connected, fitAddon, searchAddon, searchBar, webLinksAddon, scrollDownHelperAddon, applyTerminalTheme]);

    useEffect(() => {
        const handleColorModeUpdated = () => {
            applyTerminalTheme();
            if (terminal.element) {
                terminal.refresh(0, terminal.rows - 1);
            }
        };

        window.addEventListener(PANEL_COLOR_MODE_UPDATED_EVENT, handleColorModeUpdated);

        return () => {
            window.removeEventListener(PANEL_COLOR_MODE_UPDATED_EVENT, handleColorModeUpdated);
        };
    }, [terminal, applyTerminalTheme]);

    useEventListener(
        'resize',
        debounce(() => {
            if (terminal.element) {
                fitAddon.fit();
            }
        }, 100)
    );

    useEffect(() => {
        const listeners: Record<string, (s: string) => void> = {
            [SocketEvent.STATUS]: handlePowerChangeEvent,
            [SocketEvent.CONSOLE_OUTPUT]: handleConsoleOutput,
            [SocketEvent.INSTALL_OUTPUT]: handleConsoleOutput,
            [SocketEvent.TRANSFER_LOGS]: handleConsoleOutput,
            [SocketEvent.TRANSFER_STATUS]: handleTransferStatus,
            [SocketEvent.DAEMON_MESSAGE]: (line) => handleConsoleOutput(line, true),
            [SocketEvent.DAEMON_ERROR]: handleDaemonErrorOutput,
        };

        if (connected && instance) {
            // Do not clear the console if the server is being transferred.
            if (!isTransferring) {
                clearPendingWrites();
                terminal.clear();
            }

            Object.keys(listeners).forEach((key: string) => {
                instance.addListener(key, listeners[key]);
            });
            instance.send(SocketRequest.SEND_LOGS);
        }

        return () => {
            if (instance) {
                Object.keys(listeners).forEach((key: string) => {
                    instance.removeListener(key, listeners[key]);
                });
            }
        };
    }, [
        connected,
        instance,
        isTransferring,
        terminal,
        clearPendingWrites,
        handlePowerChangeEvent,
        handleConsoleOutput,
        handleTransferStatus,
        handleDaemonErrorOutput,
    ]);

    useEffect(
        () => () => {
            clearPendingWrites();
        },
        [clearPendingWrites]
    );

    return (
        <div className={classNames(styles.terminal, 'relative')}>
            <SpinnerOverlay visible={!connected} size={'large'} />
            <div
                className={classNames(styles.container, styles.overflows_container, { 'rounded-b': !canSendCommands })}
            >
                <div className={'h-full'}>
                    <div id={styles.terminal} ref={ref} />
                </div>
            </div>
            {canSendCommands && (
                <div className={classNames('relative', styles.overflows_container)}>
                    <input
                        className={classNames('peer', styles.command_input)}
                        type={'text'}
                        placeholder={'Type a command...'}
                        aria-label={'Console command input.'}
                        disabled={!instance || !connected}
                        onKeyDown={handleCommandKeyDown}
                        autoCorrect={'off'}
                        autoCapitalize={'none'}
                    />
                    <div
                        className={classNames(
                            'text-gray-100 peer-focus:text-gray-50 peer-focus:animate-pulse',
                            styles.command_icon
                        )}
                    >
                        <ChevronDoubleRightIcon className={'w-4 h-4'} />
                    </div>
                    <CommandRow />
                </div>
            )}
        </div>
    );
};
