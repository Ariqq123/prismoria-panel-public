import React, { useEffect, useRef, useState } from 'react';
import { Websocket } from '@/plugins/Websocket';
import { ServerContext } from '@/state/server';
import getWebsocketToken from '@/api/server/getWebsocketToken';
import ContentContainer from '@/components/elements/ContentContainer';
import { CSSTransition } from 'react-transition-group';
import Spinner from '@/components/elements/Spinner';
import tw from 'twin.macro';

const reconnectErrors = ['jwt: exp claim is invalid', 'jwt: created too far in past (denylist)'];

export default () => {
    let updatingToken = false;
    const [error, setError] = useState<'connecting' | string>('');
    const EXTERNAL_LIMITED_MESSAGE =
        'Live websocket console is limited for this external panel. Command sending is still available.';
    const { connected, instance } = ServerContext.useStoreState((state) => state.socket);
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const isExternal = ServerContext.useStoreState((state) => state.server.data?.isExternal || false);
    const websocketSupported = ServerContext.useStoreState(
        (state) => state.server.data?.externalCapabilities?.websocket !== false
    );
    const lastStatus = useRef<string | null>(null);
    const setServerStatus = ServerContext.useStoreActions((actions) => actions.status.setServerStatus);
    const setServerFromState = ServerContext.useStoreActions((actions) => actions.server.setServerFromState);
    const { setInstance, setConnectionState } = ServerContext.useStoreActions((actions) => actions.socket);

    const setExternalConsoleLimited = (message: string, socket?: Websocket) => {
        setError(message);
        setConnectionState(false);
        setServerFromState((server) => ({
            ...server,
            externalCapabilities: {
                ...(server.externalCapabilities || {}),
                websocket: false,
            },
        }));
        if (socket) {
            socket.close();
        }

        setInstance(null);
    };

    const updateToken = (uuid: string, socket: Websocket) => {
        if (updatingToken) return;

        updatingToken = true;
        getWebsocketToken(uuid)
            .then((data) => socket.setToken(data.token, true))
            .catch((error) => console.error(error))
            .then(() => {
                updatingToken = false;
            });
    };

    const connect = (uuid: string, isExternalServer: boolean) => {
        const socket = new Websocket();
        let allowLimitedFallback = isExternalServer;
        let authenticated = false;

        const handleExternalInitialSocketFailure = () => {
            if (!isExternalServer || !allowLimitedFallback || authenticated) {
                return false;
            }

            setExternalConsoleLimited(EXTERNAL_LIMITED_MESSAGE, socket);

            return true;
        };

        socket.on('auth success', () => {
            authenticated = true;
            setConnectionState(true);
        });
        socket.on('SOCKET_CLOSE', () => {
            if (handleExternalInitialSocketFailure()) {
                return;
            }

            setConnectionState(false);
        });
        socket.on('SOCKET_ERROR', () => {
            if (handleExternalInitialSocketFailure()) {
                return;
            }

            setError('connecting');
            setConnectionState(false);
        });
        socket.on('status', (status) => {
            if (lastStatus.current === status) {
                return;
            }

            lastStatus.current = status;
            setServerStatus(status);
        });

        socket.on('daemon error', (message) => {
            console.warn('Got error message from daemon socket:', message);
        });

        socket.on('token expiring', () => updateToken(uuid, socket));
        socket.on('token expired', () => updateToken(uuid, socket));
        socket.on('jwt error', (error: string) => {
            setConnectionState(false);
            console.warn('JWT validation error from wings:', error);

            if (reconnectErrors.find((v) => error.toLowerCase().indexOf(v) >= 0)) {
                updateToken(uuid, socket);
            } else {
                setError(
                    'There was an error validating the credentials provided for the websocket. Please refresh the page.'
                );
            }
        });

        socket.on('transfer status', (status: string) => {
            if (status === 'starting' || status === 'success') {
                return;
            }

            // This code forces a reconnection to the websocket which will connect us to the target node instead of the source node
            // in order to be able to receive transfer logs from the target node.
            socket.close();
            setError('connecting');
            setConnectionState(false);
            setInstance(null);
            connect(uuid, isExternalServer);
        });

        getWebsocketToken(uuid)
            .then((data) => {
                allowLimitedFallback = isExternalServer;

                // Connect and then set the authentication token.
                socket.setToken(data.token).connect(data.socket);

                // Once that is done, set the instance.
                setInstance(socket);
            })
            .catch((error) => {
                if (allowLimitedFallback) {
                    setExternalConsoleLimited(EXTERNAL_LIMITED_MESSAGE, socket);

                    return;
                }

                console.error(error);
                setError('connecting');
            });
    };

    useEffect(() => {
        connected && setError('');
    }, [connected]);

    useEffect(() => {
        lastStatus.current = null;
    }, [uuid]);

    useEffect(() => {
        return () => {
            instance && instance.close();
        };
    }, [instance]);

    useEffect(() => {
        // If there is already an instance or there is no server, just exit out of this process
        // since we don't need to make a new connection.
        if (instance || !uuid) {
            return;
        }

        if (isExternal && !websocketSupported) {
            setError('Live websocket console is limited for this external panel.');
            setConnectionState(false);

            return;
        }

        connect(uuid, isExternal);
    }, [uuid, isExternal, websocketSupported]);

    return error ? (
        <CSSTransition timeout={150} in appear classNames={'fade'}>
            <div css={tw`bg-red-500 py-2`}>
                <ContentContainer css={tw`flex items-center justify-center`}>
                    {error === 'connecting' ? (
                        <>
                            <Spinner size={'small'} />
                            <p css={tw`ml-2 text-sm text-red-100`}>
                                We&apos;re having some trouble connecting to your server, please wait...
                            </p>
                        </>
                    ) : (
                        <p css={tw`ml-2 text-sm text-white`}>{error}</p>
                    )}
                </ContentContainer>
            </div>
        </CSSTransition>
    ) : null;
};
