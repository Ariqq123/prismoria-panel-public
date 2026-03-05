import TransferListener from '@/components/server/TransferListener';
import React, { useEffect, useState } from 'react';
import { NavLink, useRouteMatch } from 'react-router-dom';
import NavigationBar from '@/components/NavigationBar';
import WebsocketHandler from '@/components/server/WebsocketHandler';
import { ServerContext } from '@/state/server';
import { CSSTransition } from 'react-transition-group';
import { ServerError } from '@/components/elements/ScreenBlock';
import { httpErrorToHuman } from '@/api/http';
import { useStoreState } from 'easy-peasy';
import InstallListener from '@/components/server/InstallListener';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faExternalLinkAlt } from '@fortawesome/free-solid-svg-icons';
import Can from '@/components/elements/Can';
import Sidebar from '@/components/Sidebar';
import routes from '@/routers/routes';
import { IconProp } from '@fortawesome/fontawesome-svg-core';

import { NavigationRouter } from '@blueprint/extends/routers/ServerRouter';
import AdditionalServerItems from '@blueprint/components/Navigation/SubNavigation/AdditionalServerItems';
import LoadingPage from '@/components/elements/LoadingPage';

export default () => {
    const match = useRouteMatch<{ id: string }>();

    const rootAdmin = useStoreState((state) => state.user.data!.rootAdmin);
    const [error, setError] = useState('');

    const id = ServerContext.useStoreState((state) => state.server.data?.id);
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const serverId = ServerContext.useStoreState((state) => state.server.data?.internalId);
    const getServer = ServerContext.useStoreActions((actions) => actions.server.getServer);
    const clearServerState = ServerContext.useStoreActions((actions) => actions.clearServerState);

    const to = (value: string, url = false) => {
        if (value === '/') {
            return url ? match.url : match.path;
        }

        return `${(url ? match.url : match.path).replace(/\/*$/, '')}/${value.replace(/^\/+/, '')}`;
    };

    useEffect(
        () => () => {
            clearServerState();
        },
        []
    );

    useEffect(() => {
        document.body.classList.add('server-sidebar-layout');

        return () => {
            document.body.classList.remove('server-sidebar-layout');
        };
    }, []);

    useEffect(() => {
        setError('');

        getServer(match.params.id).catch((error) => {
            console.error(error);
            setError(httpErrorToHuman(error));
        });

        return () => {
            clearServerState();
        };
    }, [match.params.id]);

    return (
        <React.Fragment key={'server-router'}>
            <NavigationBar />
            {!uuid || !id ? (
                error ? (
                    <ServerError message={error} />
                ) : (
                    <LoadingPage
                        title={'Loading Server'}
                        message={'Syncing server data and connection status...'}
                        compact
                    />
                )
            ) : (
                <>
                    <CSSTransition timeout={150} classNames={'fade'} appear in>
                        <Sidebar>
                            {routes.server
                                .filter((route) => !!route.name)
                                .map((route) =>
                                    route.permission ? (
                                        <Can key={route.path} action={route.permission} matchAny>
                                            <NavLink to={to(route.path, true)} exact={route.exact}>
                                                <div className={'icon'}>
                                                    <FontAwesomeIcon icon={route.iconProp as IconProp} />
                                                </div>
                                                {route.name}
                                            </NavLink>
                                        </Can>
                                    ) : (
                                        <NavLink key={route.path} to={to(route.path, true)} exact={route.exact}>
                                            <div className={'icon'}>
                                                <FontAwesomeIcon icon={route.iconProp as IconProp} />
                                            </div>
                                            {route.name}
                                        </NavLink>
                                    )
                                )}
                            <AdditionalServerItems />
                            {rootAdmin && (
                                // eslint-disable-next-line react/jsx-no-target-blank
                                <a href={`/admin/servers/view/${serverId}`} target={'_blank'}>
                                    <div className={'icon'}>
                                        <FontAwesomeIcon icon={faExternalLinkAlt} />
                                    </div>
                                    Admin
                                </a>
                            )}
                        </Sidebar>
                    </CSSTransition>
                    <InstallListener />
                    <TransferListener />
                    <WebsocketHandler />
                    <NavigationRouter />
                </>
            )}
        </React.Fragment>
    );
};
