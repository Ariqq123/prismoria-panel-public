import React, { ReactNode, useCallback, useEffect, useRef, useState } from 'react';
import { useLocation } from 'react-router-dom';
import '@/assets/css/sidebar.css';

type ParentProps = {
    children: ReactNode;
};

const MOBILE_BREAKPOINT = 1150;
const DESKTOP_COLLAPSE_STORAGE_KEY = 'server_sidebar_collapsed';
const MOBILE_MEDIA_QUERY = `(max-width: ${MOBILE_BREAKPOINT}px)`;
const DESKTOP_COLLAPSE_LOCK_MS = 260;
const ACTIVE_INDICATOR_MOVE_THRESHOLD = 1;
const ACTIVE_INDICATOR_FLASH_MS = 360;

type ActiveIndicatorState = {
    top: number;
    left: number;
    width: number;
    height: number;
    visible: boolean;
};

const INITIAL_ACTIVE_INDICATOR: ActiveIndicatorState = {
    top: 0,
    left: 0,
    width: 0,
    height: 0,
    visible: false,
};

export default ({ children }: Omit<ParentProps, 'render'>) => {
    const location = useLocation();
    const sidebarRef = useRef<HTMLDivElement>(null);
    const [isMobileSidebarOpen, setMobileSidebarOpen] = useState(false);
    const desktopTransitionTimer = useRef<number | null>(null);
    const indicatorFlashTimer = useRef<number | null>(null);
    const lastIndicatorPosition = useRef<{ top: number; left: number } | null>(null);
    const activeIndicatorRef = useRef<ActiveIndicatorState>(INITIAL_ACTIVE_INDICATOR);
    const desktopTransitionLocked = useRef(false);
    const [isDesktopSidebarTransitioning, setDesktopSidebarTransitioning] = useState(false);
    const [isIndicatorMoving, setIndicatorMoving] = useState(false);
    const [activeIndicator, setActiveIndicator] = useState<ActiveIndicatorState>(INITIAL_ACTIVE_INDICATOR);
    const [isDesktopSidebarCollapsed, setDesktopSidebarCollapsed] = useState<boolean>(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return window.localStorage.getItem(DESKTOP_COLLAPSE_STORAGE_KEY) === '1';
    });

    const triggerIndicatorFlash = useCallback(() => {
        setIndicatorMoving(true);

        if (indicatorFlashTimer.current !== null) {
            window.clearTimeout(indicatorFlashTimer.current);
        }

        indicatorFlashTimer.current = window.setTimeout(() => {
            setIndicatorMoving(false);
            indicatorFlashTimer.current = null;
        }, ACTIVE_INDICATOR_FLASH_MS);
    }, []);

    const updateActiveIndicator = useCallback(() => {
        const sidebar = sidebarRef.current;
        if (!sidebar) {
            return;
        }

        const activeIcon = sidebar.querySelector<HTMLElement>('a.active > .icon');
        if (!activeIcon) {
            if (activeIndicatorRef.current.visible) {
                const next = { ...activeIndicatorRef.current, visible: false };
                activeIndicatorRef.current = next;
                setActiveIndicator(next);
            }
            lastIndicatorPosition.current = null;
            return;
        }

        const sidebarRect = sidebar.getBoundingClientRect();
        const iconRect = activeIcon.getBoundingClientRect();
        const next = {
            top: iconRect.top - sidebarRect.top + sidebar.scrollTop,
            left: iconRect.left - sidebarRect.left + sidebar.scrollLeft,
            width: iconRect.width,
            height: iconRect.height,
            visible: true,
        };

        const previous = lastIndicatorPosition.current;
        if (
            previous &&
            (Math.abs(previous.top - next.top) > ACTIVE_INDICATOR_MOVE_THRESHOLD ||
                Math.abs(previous.left - next.left) > ACTIVE_INDICATOR_MOVE_THRESHOLD)
        ) {
            triggerIndicatorFlash();
        }

        lastIndicatorPosition.current = { top: next.top, left: next.left };
        const current = activeIndicatorRef.current;
        const hasMeaningfulChange =
            current.visible !== next.visible ||
            Math.abs(current.top - next.top) > ACTIVE_INDICATOR_MOVE_THRESHOLD ||
            Math.abs(current.left - next.left) > ACTIVE_INDICATOR_MOVE_THRESHOLD ||
            Math.abs(current.width - next.width) > ACTIVE_INDICATOR_MOVE_THRESHOLD ||
            Math.abs(current.height - next.height) > ACTIVE_INDICATOR_MOVE_THRESHOLD;

        if (!hasMeaningfulChange) {
            return;
        }

        activeIndicatorRef.current = next;
        setActiveIndicator(next);
    }, [triggerIndicatorFlash]);

    useEffect(() => {
        setMobileSidebarOpen(false);
    }, [location.pathname, location.search, location.hash]);

    useEffect(() => {
        const frame = window.requestAnimationFrame(updateActiveIndicator);

        return () => {
            window.cancelAnimationFrame(frame);
        };
    }, [
        updateActiveIndicator,
        location.pathname,
        location.search,
        location.hash,
        isMobileSidebarOpen,
        isDesktopSidebarCollapsed,
    ]);

    useEffect(() => {
        const mediaQuery = window.matchMedia(MOBILE_MEDIA_QUERY);
        const closeMobileSidebarOnDesktop = () => {
            if (!mediaQuery.matches) {
                setMobileSidebarOpen(false);
            }
        };

        closeMobileSidebarOnDesktop();

        if (typeof mediaQuery.addEventListener === 'function') {
            mediaQuery.addEventListener('change', closeMobileSidebarOnDesktop);

            return () => {
                mediaQuery.removeEventListener('change', closeMobileSidebarOnDesktop);
            };
        }

        mediaQuery.addListener(closeMobileSidebarOnDesktop);

        return () => {
            mediaQuery.removeListener(closeMobileSidebarOnDesktop);
        };
    }, []);

    useEffect(() => {
        const onKeydown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setMobileSidebarOpen(false);
            }
        };

        document.addEventListener('keydown', onKeydown);

        return () => {
            document.removeEventListener('keydown', onKeydown);
        };
    }, []);

    useEffect(() => {
        document.body.classList.toggle('server-sidebar-mobile-open', isMobileSidebarOpen);
    }, [isMobileSidebarOpen]);

    useEffect(() => {
        document.body.classList.toggle('server-sidebar-collapsed', isDesktopSidebarCollapsed);

        if (typeof window !== 'undefined') {
            window.localStorage.setItem(DESKTOP_COLLAPSE_STORAGE_KEY, isDesktopSidebarCollapsed ? '1' : '0');
        }
    }, [isDesktopSidebarCollapsed]);

    useEffect(() => {
        document.body.classList.toggle('server-sidebar-transitioning', isDesktopSidebarTransitioning);
    }, [isDesktopSidebarTransitioning]);

    useEffect(() => {
        window.dispatchEvent(
            new CustomEvent('server-sidebar:state', {
                detail: {
                    mobileOpen: isMobileSidebarOpen,
                    desktopCollapsed: isDesktopSidebarCollapsed,
                    desktopTransitioning: isDesktopSidebarTransitioning,
                },
            })
        );
    }, [isMobileSidebarOpen, isDesktopSidebarCollapsed, isDesktopSidebarTransitioning]);

    useEffect(
        () => () => {
            if (desktopTransitionTimer.current !== null) {
                window.clearTimeout(desktopTransitionTimer.current);
            }
            if (indicatorFlashTimer.current !== null) {
                window.clearTimeout(indicatorFlashTimer.current);
            }

            document.body.classList.remove('server-sidebar-mobile-open');
            document.body.classList.remove('server-sidebar-collapsed');
            document.body.classList.remove('server-sidebar-transitioning');
        },
        []
    );

    useEffect(() => {
        const sidebar = sidebarRef.current;
        if (!sidebar) {
            return;
        }

        let frame: number | null = null;
        const scheduleUpdate = () => {
            if (frame !== null) {
                window.cancelAnimationFrame(frame);
            }

            frame = window.requestAnimationFrame(() => {
                updateActiveIndicator();
                frame = null;
            });
        };

        sidebar.addEventListener('scroll', scheduleUpdate, { passive: true });
        window.addEventListener('resize', scheduleUpdate);
        window.addEventListener('server-sidebar:state', scheduleUpdate);

        scheduleUpdate();

        return () => {
            sidebar.removeEventListener('scroll', scheduleUpdate);
            window.removeEventListener('resize', scheduleUpdate);
            window.removeEventListener('server-sidebar:state', scheduleUpdate);

            if (frame !== null) {
                window.cancelAnimationFrame(frame);
            }
        };
    }, [updateActiveIndicator]);

    useEffect(() => {
        const onToggleMobile = () => setMobileSidebarOpen((open) => !open);
        const onToggleDesktop = () => {
            if (window.matchMedia(MOBILE_MEDIA_QUERY).matches || desktopTransitionLocked.current) {
                return;
            }

            desktopTransitionLocked.current = true;
            setDesktopSidebarTransitioning(true);
            setDesktopSidebarCollapsed((collapsed) => !collapsed);

            if (desktopTransitionTimer.current !== null) {
                window.clearTimeout(desktopTransitionTimer.current);
            }

            desktopTransitionTimer.current = window.setTimeout(() => {
                desktopTransitionLocked.current = false;
                setDesktopSidebarTransitioning(false);
                desktopTransitionTimer.current = null;
            }, DESKTOP_COLLAPSE_LOCK_MS);
        };

        window.addEventListener('server-sidebar:toggle-mobile', onToggleMobile);
        window.addEventListener('server-sidebar:toggle-desktop', onToggleDesktop);

        return () => {
            window.removeEventListener('server-sidebar:toggle-mobile', onToggleMobile);
            window.removeEventListener('server-sidebar:toggle-desktop', onToggleDesktop);
        };
    }, []);

    const onSidebarClick = (event: React.MouseEvent<HTMLDivElement>) => {
        const target = event.target as HTMLElement;

        if (window.matchMedia(MOBILE_MEDIA_QUERY).matches && target.closest('a')) {
            setMobileSidebarOpen(false);
        }
    };

    return (
        <>
            <div
                className={`sidebar-overlay ${isMobileSidebarOpen ? 'visible' : ''}`}
                onClick={() => setMobileSidebarOpen(false)}
            />
            <div
                ref={sidebarRef}
                className={`sidebar ${isMobileSidebarOpen ? 'active-nav' : ''}`}
                id='sidebar'
                onClick={onSidebarClick}
            >
                <div
                    className={`sidebar-active-flash ${activeIndicator.visible ? 'visible' : ''} ${
                        isIndicatorMoving ? 'moving' : ''
                    }`}
                    style={{
                        transform: `translate3d(${activeIndicator.left}px, ${activeIndicator.top}px, 0)`,
                        width: `${activeIndicator.width}px`,
                        height: `${activeIndicator.height}px`,
                    }}
                    aria-hidden={'true'}
                />
                {children}
            </div>
        </>
    );
};
