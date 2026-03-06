import React, { lazy, useCallback, useEffect, useState } from 'react';
import { hot } from 'react-hot-loader/root';
import { Route, Router, Switch, useLocation } from 'react-router-dom';
import { StoreProvider } from 'easy-peasy';
import { store } from '@/state';
import { SiteSettings } from '@/state/settings';
import ProgressBar from '@/components/elements/ProgressBar';
import { NotFound } from '@/components/elements/ScreenBlock';
import tw from 'twin.macro';
import GlobalStylesheet from '@/assets/css/GlobalStylesheet';
import { history } from '@/components/history';
import { setupInterceptors } from '@/api/interceptors';
import AuthenticatedRoute from '@/components/elements/AuthenticatedRoute';
import { ServerContext } from '@/state/server';
import {
    getPanelBackgroundPreference,
    PANEL_BACKGROUND_PREFERENCE_UPDATED_EVENT,
} from '@/lib/panelBackgroundPreference';
import {
    getPanelColorMode,
    initializePanelColorMode,
    listenPanelColorModeStorageSync,
    PANEL_COLOR_MODE_UPDATED_EVENT,
} from '@/lib/colorMode';
import '@/assets/tailwind.css';
import ErrorBoundary from '@/components/elements/ErrorBoundary';
import LoadingPage from '@/components/elements/LoadingPage';

const DashboardRouter = lazy(() => import(/* webpackChunkName: "dashboard" */ '@/routers/DashboardRouter'));
const loadServerRouter = () => import(/* webpackChunkName: "server" */ '@/routers/ServerRouter');
const ServerRouter = lazy(loadServerRouter);
const AuthenticationRouter = lazy(() => import(/* webpackChunkName: "auth" */ '@/routers/AuthenticationRouter'));
const ExternalPanelQuickAddButton = lazy(
    () => import(/* webpackChunkName: "external-panel-quick-add" */ '@/components/dashboard/ExternalPanelQuickAddButton')
);
const AiChatWidget = lazy(() => import(/* webpackChunkName: "ai-chat-widget" */ '@/components/chatbot/AiChatWidget'));
const FloatingActionDock = lazy(() => import(/* webpackChunkName: "floating-action-dock" */ '@/components/dock/FloatingActionDock'));

interface ExtendedWindow extends Window {
    SiteConfiguration?: SiteSettings;
    PterodactylUser?: {
        uuid: string;
        username: string;
        email: string;
        /* eslint-disable camelcase */
        root_admin: boolean;
        use_totp: boolean;
        language: string;
        updated_at: string;
        created_at: string;
        /* eslint-enable camelcase */
    };
}

setupInterceptors(history);

const PANEL_BACKGROUND_OVERLAY_OPACITY = 0.35;
const PANEL_BACKGROUND_VIDEO_ID = 'panel-background-video';
const PANEL_BACKGROUND_VIDEO_OVERLAY_ID = 'panel-background-video-overlay';
const PANEL_BACKGROUND_VIDEO_EXTENSIONS = ['.mp4', '.webm'];
const SERVER_ROUTE_PATTERN = /^\/server\/([^/]+)/;
const PANEL_BACKGROUND_DARK_OVERLAY_RGB = '26, 26, 26';
const PANEL_BACKGROUND_LIGHT_OVERLAY_RGB = '241, 245, 249';
const PANEL_BACKGROUND_DARK_FALLBACK = 'rgb(23, 23, 23)';
const PANEL_BACKGROUND_LIGHT_FALLBACK = 'rgb(241, 245, 249)';
const PANEL_LIGHT_MODE_DEFAULT_BACKGROUND = '/default-img/defaul-background-white.jpg';

const getPanelBackgroundTone = (): { overlayRgb: string; fallbackColor: string } => {
    const mode = document.body.dataset.colorMode || getPanelColorMode();
    if (mode === 'light') {
        return {
            overlayRgb: PANEL_BACKGROUND_LIGHT_OVERLAY_RGB,
            fallbackColor: PANEL_BACKGROUND_LIGHT_FALLBACK,
        };
    }

    return {
        overlayRgb: PANEL_BACKGROUND_DARK_OVERLAY_RGB,
        fallbackColor: PANEL_BACKGROUND_DARK_FALLBACK,
    };
};

const resolvePanelDefaultBackgroundByMode = (): string => {
    const mode = document.body.dataset.colorMode || getPanelColorMode();
    return mode === 'light' ? PANEL_LIGHT_MODE_DEFAULT_BACKGROUND : '';
};

const hasVideoBackgroundExtension = (value: string): boolean => {
    const withoutHash = value.split('#')[0];
    const withoutQuery = withoutHash.split('?')[0];
    const normalized = withoutQuery.toLowerCase();

    return PANEL_BACKGROUND_VIDEO_EXTENSIONS.some((extension) => normalized.endsWith(extension));
};

const syncPanelBackgroundVideoPlayback = () => {
    const video = document.getElementById(PANEL_BACKGROUND_VIDEO_ID) as HTMLVideoElement | null;
    if (!video) {
        return;
    }

    if (document.visibilityState === 'hidden') {
        video.pause();

        return;
    }

    video.play().catch(() => undefined);
};

const removePanelBackgroundMediaLayers = () => {
    document.getElementById(PANEL_BACKGROUND_VIDEO_ID)?.remove();
    document.getElementById(PANEL_BACKGROUND_VIDEO_OVERLAY_ID)?.remove();

    const appRoot = document.getElementById('app');
    if (appRoot) {
        appRoot.style.removeProperty('position');
        appRoot.style.removeProperty('z-index');
    }
};

const ensurePanelBackgroundVideoLayers = (videoUrl: string) => {
    const tone = getPanelBackgroundTone();
    let video = document.getElementById(PANEL_BACKGROUND_VIDEO_ID) as HTMLVideoElement | null;
    if (!video) {
        video = document.createElement('video');
        video.id = PANEL_BACKGROUND_VIDEO_ID;
        video.autoplay = true;
        video.loop = true;
        video.muted = true;
        video.playsInline = true;
        video.preload = 'metadata';
        document.body.appendChild(video);
    }

    if (video.getAttribute('src') !== videoUrl) {
        video.setAttribute('src', videoUrl);
    }

    Object.assign(video.style, {
        position: 'fixed',
        top: '0',
        left: '0',
        width: '100vw',
        height: '100vh',
        objectFit: 'cover',
        objectPosition: 'center center',
        pointerEvents: 'none',
        zIndex: '0',
        transform: 'translateZ(0)',
    });

    let overlay = document.getElementById(PANEL_BACKGROUND_VIDEO_OVERLAY_ID) as HTMLDivElement | null;
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = PANEL_BACKGROUND_VIDEO_OVERLAY_ID;
        document.body.appendChild(overlay);
    }

    Object.assign(overlay.style, {
        position: 'fixed',
        top: '0',
        left: '0',
        right: '0',
        bottom: '0',
        background: `rgba(${tone.overlayRgb}, ${PANEL_BACKGROUND_OVERLAY_OPACITY})`,
        pointerEvents: 'none',
        zIndex: '1',
    });

    const appRoot = document.getElementById('app');
    if (appRoot) {
        appRoot.style.position = 'relative';
        appRoot.style.zIndex = '2';
    }

    syncPanelBackgroundVideoPlayback();
};

const setPanelBackgroundImage = (image: string | undefined) => {
    const tone = getPanelBackgroundTone();
    const value = typeof image === 'string' ? image.trim() : '';
    if (!value) {
        removePanelBackgroundMediaLayers();
        document.body.style.removeProperty('background-image');
        document.body.style.removeProperty('background-size');
        document.body.style.removeProperty('background-position');
        document.body.style.removeProperty('background-repeat');
        document.body.style.removeProperty('background-attachment');
        document.body.style.removeProperty('background-color');

        return;
    }

    document.body.style.backgroundColor = tone.fallbackColor;
    document.body.style.backgroundAttachment = 'scroll';

    if (hasVideoBackgroundExtension(value)) {
        document.body.style.backgroundImage = 'none';
        document.body.style.backgroundSize = 'auto';
        document.body.style.backgroundPosition = 'center center';
        document.body.style.backgroundRepeat = 'no-repeat';
        ensurePanelBackgroundVideoLayers(value);

        return;
    }

    removePanelBackgroundMediaLayers();
    const escaped = value.replace(/"/g, '\\"');
    document.body.style.backgroundImage = `linear-gradient(
        rgba(${tone.overlayRgb}, ${PANEL_BACKGROUND_OVERLAY_OPACITY}),
        rgba(${tone.overlayRgb}, ${PANEL_BACKGROUND_OVERLAY_OPACITY})
    ), url("${escaped}")`;
    document.body.style.backgroundSize = 'cover';
    document.body.style.backgroundPosition = 'center center';
    document.body.style.backgroundRepeat = 'no-repeat';
};

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

const FLOATING_WIDGET_DELAY_MS = 350;

const FloatingWidgets = () => {
    const location = useLocation();
    const [shouldMount, setShouldMount] = useState(false);
    const isAuthRoute = location.pathname.startsWith('/auth');

    useEffect(() => {
        if (isAuthRoute) {
            setShouldMount(false);

            return;
        }

        const timer = window.setTimeout(() => {
            setShouldMount(true);
        }, FLOATING_WIDGET_DELAY_MS);

        return () => {
            window.clearTimeout(timer);
        };
    }, [isAuthRoute, location.pathname]);

    if (isAuthRoute || !shouldMount) {
        return null;
    }

    return (
        <React.Suspense fallback={null}>
            <ExternalPanelQuickAddButton hideTrigger />
            <AiChatWidget hideTrigger />
            <FloatingActionDock />
        </React.Suspense>
    );
};

const App = () => {
    const { PterodactylUser, SiteConfiguration } = window as ExtendedWindow;
    if (PterodactylUser && !store.getState().user.data) {
        store.getActions().user.setUserData({
            uuid: PterodactylUser.uuid,
            username: PterodactylUser.username,
            email: PterodactylUser.email,
            language: PterodactylUser.language,
            rootAdmin: PterodactylUser.root_admin,
            useTotp: PterodactylUser.use_totp,
            createdAt: new Date(PterodactylUser.created_at),
            updatedAt: new Date(PterodactylUser.updated_at),
        });
    }

    if (!store.getState().settings.data) {
        store.getActions().settings.setSettings(SiteConfiguration!);
    }

    const backgroundImage = SiteConfiguration?.background_image;
    const userUuid = PterodactylUser?.uuid;

    const applyPanelBackground = useCallback(() => {
        const activeServerIdentifier = resolveServerIdentifierFromPathname(history.location.pathname);
        const preference = getPanelBackgroundPreference(userUuid, activeServerIdentifier);
        const userBackgroundImage = preference.enabled ? preference.imageUrl.trim() : '';
        const siteBackgroundImage = typeof backgroundImage === 'string' ? backgroundImage.trim() : '';
        const fallbackBackgroundImage = resolvePanelDefaultBackgroundByMode();

        setPanelBackgroundImage(userBackgroundImage || siteBackgroundImage || fallbackBackgroundImage);
    }, [backgroundImage, userUuid]);

    useEffect(() => {
        applyPanelBackground();

        const handlePreferenceChange = () => {
            applyPanelBackground();
        };
        const handleColorModeChange = () => {
            applyPanelBackground();
        };

        window.addEventListener(PANEL_BACKGROUND_PREFERENCE_UPDATED_EVENT, handlePreferenceChange);
        window.addEventListener(PANEL_COLOR_MODE_UPDATED_EVENT, handleColorModeChange);
        const unlistenHistory = history.listen(() => {
            applyPanelBackground();
        });

        return () => {
            window.removeEventListener(PANEL_BACKGROUND_PREFERENCE_UPDATED_EVENT, handlePreferenceChange);
            window.removeEventListener(PANEL_COLOR_MODE_UPDATED_EVENT, handleColorModeChange);
            unlistenHistory();
        };
    }, [applyPanelBackground]);

    useEffect(() => {
        const onVisibilityChange = () => {
            syncPanelBackgroundVideoPlayback();
        };

        document.addEventListener('visibilitychange', onVisibilityChange);

        return () => {
            document.removeEventListener('visibilitychange', onVisibilityChange);
        };
    }, []);

    useEffect(() => {
        initializePanelColorMode();
        const disposeStorageSync = listenPanelColorModeStorageSync();

        return () => {
            disposeStorageSync();
        };
    }, []);

    useEffect(() => {
        if (window.location.pathname.startsWith('/auth')) {
            return;
        }

        const preloadServerRouter = () => {
            void loadServerRouter().catch(() => undefined);
        };

        const win = window as Window & {
            requestIdleCallback?: (callback: () => void, options?: { timeout: number }) => number;
            cancelIdleCallback?: (handle: number) => void;
        };

        if (typeof win.requestIdleCallback === 'function') {
            const idleHandle = win.requestIdleCallback(preloadServerRouter, { timeout: 1200 });

            return () => {
                if (typeof win.cancelIdleCallback === 'function') {
                    win.cancelIdleCallback(idleHandle);
                }
            };
        }

        const timer = window.setTimeout(preloadServerRouter, 150);

        return () => window.clearTimeout(timer);
    }, []);

    return (
        <>
            <GlobalStylesheet />
            <StoreProvider store={store}>
                <ProgressBar />
                <div css={tw`mx-auto w-auto`} className='nook-container'>
                    <Router history={history}>
                        <Switch>
                            <Route path={'/auth'}>
                                <React.Suspense
                                    fallback={
                                        <LoadingPage
                                            title={'Loading Authentication'}
                                            message={'Securing your session...'}
                                            compact
                                        />
                                    }
                                >
                                    <ErrorBoundary>
                                        <AuthenticationRouter />
                                    </ErrorBoundary>
                                </React.Suspense>
                            </Route>
                            <AuthenticatedRoute path={'/server/:id'}>
                                <React.Suspense
                                    fallback={
                                        <LoadingPage
                                            title={'Loading Server'}
                                            message={'Fetching server state and resources...'}
                                            compact
                                        />
                                    }
                                >
                                    <ErrorBoundary>
                                        <ServerContext.Provider>
                                            <ServerRouter />
                                        </ServerContext.Provider>
                                    </ErrorBoundary>
                                </React.Suspense>
                            </AuthenticatedRoute>
                            <AuthenticatedRoute path={'/'}>
                                <React.Suspense
                                    fallback={
                                        <LoadingPage
                                            title={'Loading Prismoria Network'}
                                            message={'Preparing your server list...'}
                                            compact
                                        />
                                    }
                                >
                                    <ErrorBoundary>
                                        <DashboardRouter />
                                    </ErrorBoundary>
                                </React.Suspense>
                            </AuthenticatedRoute>
                            <Route path={'*'}>
                                <NotFound />
                            </Route>
                        </Switch>
                        <FloatingWidgets />
                    </Router>
                </div>
            </StoreProvider>
        </>
    );
};

export default hot(App);
