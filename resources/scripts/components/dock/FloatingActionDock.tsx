import React, { useCallback, useEffect, useRef, useState } from 'react';
import { useHistory, useLocation } from 'react-router-dom';
import { useStoreState } from 'easy-peasy';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCommentDots, faHome, faPlus } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

const MOBILE_DOCK_BREAKPOINT = 768;
const IDLE_DIM_DELAY_MS = 1650;
const LEAVE_DIM_DELAY_MS = 850;
const MAGNET_RADIUS_PX = 140;
const MAX_MAGNIFY_SCALE = 1.44;
const MAX_LIFT_PX = 12;

const clamp = (value: number, min: number, max: number): number => Math.min(max, Math.max(min, value));

const DockShell = styled.div<{ $dimmed: boolean }>`
    ${tw`fixed inset-x-0 mx-auto w-max z-[72] rounded-full border bg-neutral-900/75 px-3.5 py-2.5 shadow-2xl backdrop-blur-md`};
    bottom: max(1rem, env(safe-area-inset-bottom));
    border-color: ${({ $dimmed }) => ($dimmed ? 'rgba(82, 82, 91, 0.45)' : 'rgba(82, 82, 91, 0.82)')};
    opacity: ${({ $dimmed }) => ($dimmed ? 0.36 : 1)};
    transition: opacity 320ms cubic-bezier(0.22, 1, 0.36, 1), border-color 280ms ease;

    &::before {
        content: '';
        position: absolute;
        left: 12%;
        right: 12%;
        bottom: 2px;
        height: 1px;
        background: linear-gradient(90deg, rgba(248, 113, 113, 0), rgba(248, 113, 113, 0.34), rgba(248, 113, 113, 0));
        opacity: ${({ $dimmed }) => ($dimmed ? 0.12 : 0.6)};
        transition: opacity 260ms ease;
        pointer-events: none;
    }

    @media (max-width: 768px) {
        bottom: max(0.75rem, env(safe-area-inset-bottom));
        padding: 0.4rem 0.55rem;
        background: rgba(17, 24, 39, 0.66);
    }
`;

const DockList = styled.div`
    ${tw`flex items-end gap-2.5`};

    @media (max-width: 768px) {
        gap: 0.45rem;
    }
`;

const DockButton = styled.button`
    ${tw`relative inline-flex h-12 w-12 items-center justify-center rounded-full border border-neutral-500/60 bg-neutral-800/80 text-neutral-100`};
    transform-origin: center bottom;
    transform: translateY(calc(-1px - var(--dock-lift, 0px))) scale(var(--dock-scale, 1));
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.28);
    transition:
        transform 170ms cubic-bezier(0.22, 1, 0.36, 1),
        border-color 170ms ease,
        color 170ms ease,
        box-shadow 170ms ease;

    &::after {
        content: '';
        position: absolute;
        top: calc(100% + 5px);
        left: 17%;
        right: 17%;
        height: 11px;
        border-radius: 9999px;
        background: radial-gradient(ellipse at center, rgba(254, 202, 202, 0.52) 0%, rgba(254, 202, 202, 0.2) 40%, rgba(254, 202, 202, 0) 78%);
        opacity: var(--dock-reflection-opacity, 0.18);
        filter: blur(6px);
        transform: scaleX(calc(0.84 + (var(--dock-scale, 1) - 1) * 1.42));
        transform-origin: center;
        pointer-events: none;
        transition: opacity 170ms ease, transform 170ms cubic-bezier(0.22, 1, 0.36, 1);
    }

    &:hover,
    &:focus-visible {
        border-color: rgba(248, 113, 113, 0.86);
        color: rgb(254 202 202);
        box-shadow: 0 14px 34px rgba(0, 0, 0, 0.38);
        outline: none;
    }

    &:active {
        transform: translateY(calc(-1px - var(--dock-lift, 0px))) scale(calc(var(--dock-scale, 1) * 0.96));
    }

    @media (max-width: 768px) {
        width: 2.5rem;
        height: 2.5rem;
        font-size: 0.875rem;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.22);
    }

    @media (prefers-reduced-motion: reduce) {
        transition-duration: 1ms;
        transform: none;

        &::after {
            transition-duration: 1ms;
            transform: none;
        }

        &:active {
            transform: none;
        }
    }
`;

const isFileEditorRoute = (pathname: string) => /^\/server\/[^/]+\/files\/(edit|new)(?:\/|$)/.test(pathname);

export default () => {
    const history = useHistory();
    const location = useLocation();
    const isAuthenticated = useStoreState((state) => !!state.user.data?.uuid);
    const buttonRefs = useRef<Array<HTMLButtonElement | null>>([]);
    const idleTimer = useRef<number | null>(null);
    const [isMobileViewport, setMobileViewport] = useState(() => {
        if (typeof window === 'undefined') {
            return false;
        }

        return window.innerWidth <= MOBILE_DOCK_BREAKPOINT;
    });
    const [isDimmed, setDimmed] = useState(false);
    const [pointerX, setPointerX] = useState<number | null>(null);
    const [buttonCenters, setButtonCenters] = useState<number[]>([]);

    const clearIdleTimer = useCallback(() => {
        if (idleTimer.current !== null) {
            window.clearTimeout(idleTimer.current);
            idleTimer.current = null;
        }
    }, []);

    const armIdleTimer = useCallback(
        (delay: number = IDLE_DIM_DELAY_MS) => {
            clearIdleTimer();
            idleTimer.current = window.setTimeout(() => {
                setDimmed(true);
            }, delay);
        },
        [clearIdleTimer]
    );

    const wakeDock = useCallback(
        (nextDelay: number = IDLE_DIM_DELAY_MS) => {
            setDimmed(false);
            armIdleTimer(nextDelay);
        },
        [armIdleTimer]
    );

    const updateButtonCenters = useCallback(() => {
        const nextCenters = buttonRefs.current.map((button) => {
            if (!button) {
                return Number.NaN;
            }

            const rect = button.getBoundingClientRect();
            return rect.left + rect.width / 2;
        });

        setButtonCenters(nextCenters);
    }, []);

    useEffect(() => {
        const mediaQuery = window.matchMedia(`(max-width: ${MOBILE_DOCK_BREAKPOINT}px)`);
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
        const scheduleUpdate = () => {
            window.requestAnimationFrame(updateButtonCenters);
        };

        scheduleUpdate();
        window.addEventListener('resize', scheduleUpdate, { passive: true });
        window.addEventListener('orientationchange', scheduleUpdate);

        return () => {
            window.removeEventListener('resize', scheduleUpdate);
            window.removeEventListener('orientationchange', scheduleUpdate);
        };
    }, [updateButtonCenters, location.pathname, isMobileViewport]);

    useEffect(() => {
        wakeDock();

        return () => {
            clearIdleTimer();
        };
    }, [clearIdleTimer, wakeDock]);

    if (!isAuthenticated || location.pathname.startsWith('/auth')) {
        return null;
    }

    const showExternalApiAction = !isFileEditorRoute(location.pathname);

    const computeButtonStyle = (index: number): React.CSSProperties => {
        const center = buttonCenters[index];

        if (pointerX === null || !Number.isFinite(center)) {
            return {
                ['--dock-scale' as string]: '1',
                ['--dock-lift' as string]: '0px',
                ['--dock-reflection-opacity' as string]: isDimmed ? '0.1' : '0.2',
            };
        }

        const distance = Math.abs(pointerX - center);
        const ratio = clamp(1 - distance / MAGNET_RADIUS_PX, 0, 1);
        const eased = ratio * ratio * (3 - 2 * ratio);
        const scale = 1 + eased * (MAX_MAGNIFY_SCALE - 1);
        const lift = eased * MAX_LIFT_PX;
        const reflectionOpacity = 0.16 + eased * 0.42;

        return {
            ['--dock-scale' as string]: scale.toFixed(3),
            ['--dock-lift' as string]: `${lift.toFixed(2)}px`,
            ['--dock-reflection-opacity' as string]: reflectionOpacity.toFixed(3),
        };
    };

    return (
        <DockShell
            $dimmed={isDimmed}
            role={'toolbar'}
            aria-label={'Quick actions dock'}
            onPointerEnter={(event) => {
                wakeDock();
                if (event.pointerType === 'mouse' || event.pointerType === 'pen') {
                    setPointerX(event.clientX);
                    updateButtonCenters();
                }
            }}
            onPointerMove={(event) => {
                wakeDock();
                if (event.pointerType === 'mouse' || event.pointerType === 'pen') {
                    setPointerX(event.clientX);
                }
            }}
            onPointerLeave={() => {
                setPointerX(null);
                armIdleTimer(LEAVE_DIM_DELAY_MS);
            }}
            onTouchStart={() => {
                setPointerX(null);
                wakeDock();
            }}
            onFocusCapture={() => wakeDock()}
            onBlurCapture={() => armIdleTimer()}
        >
            <DockList>
                <DockButton
                    ref={(node) => {
                        buttonRefs.current[0] = node;
                    }}
                    style={computeButtonStyle(0)}
                    type={'button'}
                    aria-label={'Go to dashboard'}
                    onClick={() => {
                        if (location.pathname !== '/') {
                            history.push('/');
                        }
                    }}
                >
                    <FontAwesomeIcon icon={faHome} />
                </DockButton>
                <DockButton
                    ref={(node) => {
                        buttonRefs.current[1] = node;
                    }}
                    style={computeButtonStyle(1)}
                    type={'button'}
                    aria-label={'Toggle AI assistant'}
                    onClick={() => window.dispatchEvent(new CustomEvent('ai-chat:toggle'))}
                >
                    <FontAwesomeIcon icon={faCommentDots} />
                </DockButton>
                {showExternalApiAction && (
                    <DockButton
                        ref={(node) => {
                            buttonRefs.current[2] = node;
                        }}
                        style={computeButtonStyle(2)}
                        type={'button'}
                        aria-label={'Add External API connection'}
                        onClick={() => window.dispatchEvent(new CustomEvent('external-api:open'))}
                    >
                        <FontAwesomeIcon icon={faPlus} />
                    </DockButton>
                )}
            </DockList>
        </DockShell>
    );
};
