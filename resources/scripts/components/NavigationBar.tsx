import * as React from 'react';
import { useEffect, useState } from 'react';
import { Link, NavLink, useLocation } from 'react-router-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faBars, faCogs, faLayerGroup, faMoon, faSignOutAlt, faSun } from '@fortawesome/free-solid-svg-icons';
import { useStoreState } from 'easy-peasy';
import { ApplicationStore } from '@/state';
import SearchContainer from '@/components/dashboard/search/SearchContainer';
import tw, { theme } from 'twin.macro';
import styled from 'styled-components/macro';
import http from '@/api/http';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import Tooltip from '@/components/elements/tooltip/Tooltip';
import Avatar from '@/components/Avatar';
import { getPanelColorMode, PANEL_COLOR_MODE_UPDATED_EVENT, togglePanelColorMode } from '@/lib/colorMode';

import BeforeNavigation from '@blueprint/components/Navigation/NavigationBar/BeforeNavigation';
import AdditionalItems from '@blueprint/components/Navigation/NavigationBar/AdditionalItems';
import AfterNavigation from '@blueprint/components/Navigation/NavigationBar/AfterNavigation';

const RightNavigation = styled.div`
    & > a,
    & > button,
    & > .navigation-link {
        ${tw`flex items-center h-full no-underline px-6 cursor-pointer transition-all duration-150`};
        color: var(--panel-nav-text);

        &:active,
        &:hover {
            background: var(--panel-nav-hover-bg);
            color: var(--panel-nav-text-active);
        }

        &:active,
        &:hover,
        &.active {
            box-shadow: inset 0 -2px ${theme`colors.cyan.600`.toString()};
        }
    }
`;

export default () => {
    const mobileViewportQuery = '(max-width: 1150px)';
    const location = useLocation();
    const name = useStoreState((state: ApplicationStore) => state.settings.data!.name);
    const rootAdmin = useStoreState((state: ApplicationStore) => state.user.data!.rootAdmin);
    const [isLoggingOut, setIsLoggingOut] = useState(false);
    const [isServerSidebarCollapsed, setServerSidebarCollapsed] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return window.localStorage.getItem('server_sidebar_collapsed') === '1';
    });
    const [isMobileSidebarOpen, setMobileSidebarOpen] = useState(false);
    const [isDesktopSidebarTransitioning, setDesktopSidebarTransitioning] = useState(false);
    const [isMobileViewport, setMobileViewport] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return window.innerWidth <= 1150;
    });
    const [colorMode, setColorMode] = useState<'dark' | 'light'>(() => getPanelColorMode());
    const isServerRoute = /^\/server(?:\/|$)/.test(location.pathname);

    const onTriggerLogout = () => {
        setIsLoggingOut(true);
        http.post('/auth/logout').finally(() => {
            // @ts-expect-error this is valid
            window.location = '/';
        });
    };

    const onToggleServerSidebar = () => {
        if (typeof window === 'undefined') {
            return;
        }

        if (!isMobileViewport && isDesktopSidebarTransitioning) {
            return;
        }

        if (isMobileViewport) {
            window.dispatchEvent(new Event('server-sidebar:toggle-mobile'));
            return;
        }

        window.dispatchEvent(new Event('server-sidebar:toggle-desktop'));
    };

    useEffect(() => {
        const handleServerSidebarState = (event: Event) => {
            const detail = (
                event as CustomEvent<{
                    mobileOpen: boolean;
                    desktopCollapsed: boolean;
                    desktopTransitioning?: boolean;
                }>
            ).detail;
            if (!detail) {
                return;
            }

            setMobileSidebarOpen(Boolean(detail.mobileOpen));
            setServerSidebarCollapsed(Boolean(detail.desktopCollapsed));
            setDesktopSidebarTransitioning(Boolean(detail.desktopTransitioning));
        };

        window.addEventListener('server-sidebar:state', handleServerSidebarState);

        return () => {
            window.removeEventListener('server-sidebar:state', handleServerSidebarState);
        };
    }, []);

    useEffect(() => {
        const mediaQuery = window.matchMedia(mobileViewportQuery);
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
    }, [mobileViewportQuery]);

    useEffect(() => {
        const onColorModeUpdate = (event: Event) => {
            const detail = (event as CustomEvent<{ mode?: 'dark' | 'light' }>).detail;
            if (!detail?.mode) {
                return;
            }

            setColorMode(detail.mode);
        };

        window.addEventListener(PANEL_COLOR_MODE_UPDATED_EVENT, onColorModeUpdate);

        return () => {
            window.removeEventListener(PANEL_COLOR_MODE_UPDATED_EVENT, onColorModeUpdate);
        };
    }, []);

    return (
        <div className={'w-full shadow-md overflow-x-auto'} id={'NavigationBar'}>
            <BeforeNavigation />
            <SpinnerOverlay visible={isLoggingOut} />
            <div className={'mx-auto w-full flex items-center h-[3.5rem] max-w-[1200px] relative'}>
                {isServerRoute && (
                    <button
                        type={'button'}
                        className={'server-sidebar-navbar-toggle'}
                        onClick={onToggleServerSidebar}
                        disabled={!isMobileViewport && isDesktopSidebarTransitioning}
                        aria-label={
                            isMobileSidebarOpen
                                ? 'Close server menu'
                                : isServerSidebarCollapsed
                                ? 'Expand server menu'
                                : 'Collapse server menu'
                        }
                        aria-controls={'sidebar'}
                        aria-expanded={isMobileViewport ? isMobileSidebarOpen : !isServerSidebarCollapsed}
                        aria-disabled={!isMobileViewport && isDesktopSidebarTransitioning}
                    >
                        <FontAwesomeIcon icon={faBars} />
                    </button>
                )}
                <div id={'logo'} className={isServerRoute ? 'flex-1 pl-12 md:pl-14' : 'flex-1'}>
                    <Link
                        to={'/'}
                        className={
                            'text-2xl font-header font-medium px-4 no-underline text-neutral-200 hover:text-neutral-100 transition-colors duration-150'
                        }
                    >
                        {name}
                    </Link>
                </div>
                <RightNavigation className={'flex h-full items-center justify-center'}>
                    <SearchContainer />
                    <Tooltip
                        placement={'bottom'}
                        content={colorMode === 'dark' ? 'Switch to Light Mode' : 'Switch to Dark Mode'}
                    >
                        <button
                            type={'button'}
                            id={'NavigationColorMode'}
                            onClick={() => {
                                const nextMode = togglePanelColorMode();
                                setColorMode(nextMode);
                            }}
                            aria-label={colorMode === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
                        >
                            <FontAwesomeIcon icon={colorMode === 'dark' ? faSun : faMoon} />
                        </button>
                    </Tooltip>
                    <Tooltip placement={'bottom'} content={'Prismoria Network'}>
                        <NavLink to={'/'} exact id={'NavigationDashboard'}>
                            <FontAwesomeIcon icon={faLayerGroup} />
                        </NavLink>
                    </Tooltip>
                    {rootAdmin && (
                        <Tooltip placement={'bottom'} content={'Admin'}>
                            <a href={'/admin'} rel={'noreferrer'} id={'NavigationAdmin'}>
                                <FontAwesomeIcon icon={faCogs} />
                            </a>
                        </Tooltip>
                    )}
                    <AdditionalItems />
                    <Tooltip placement={'bottom'} content={'Account Settings'}>
                        <NavLink to={'/account'} id={'NavigationAccount'}>
                            <span className={'flex items-center w-5 h-5'}>
                                <Avatar.User />
                            </span>
                        </NavLink>
                    </Tooltip>
                    <Tooltip placement={'bottom'} content={'Sign Out'}>
                        <button onClick={onTriggerLogout} id={'NavigationLogout'}>
                            <FontAwesomeIcon icon={faSignOutAlt} />
                        </button>
                    </Tooltip>
                </RightNavigation>
            </div>
            <AfterNavigation />
        </div>
    );
};
