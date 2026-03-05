<head>
@php
    $defaultServerBackgroundMediaPath = '/var/www/pterodactyl/default-img/default-video.mp4';
    $defaultServerBackgroundMediaUrl = file_exists($defaultServerBackgroundMediaPath)
        ? '/default-img/default-video.mp4?v=' . filemtime($defaultServerBackgroundMediaPath)
        : '';
@endphp
<script>
document.addEventListener('DOMContentLoaded', () => {
    // Prevent double-initialization if the wrapper is injected more than once.
    if (window.__serverbackgroundsInitialized) return;
    window.__serverbackgroundsInitialized = true;

    const EXT_BASE = '/extensions/serverbackgrounds';
    const DASHBOARD_PATH = '/';

    // Use stable attributes first; keep legacy class fallback for older builds.
    const SERVER_CARD_SELECTOR = '[data-server-identifier], .dyLna-D';
    const BACKGROUND_CLASS = 'background-image';
    const BACKGROUND_VIDEO_CLASS = 'background-video';
    const BACKGROUND_MEDIA_CLASS = 'server-background-media-layer';
    const VIDEO_EXTENSIONS = ['.mp4', '.webm'];
    const IMAGE_PRELOAD_ROOT_MARGIN = '320px 0px';
    const MAX_EAGER_BACKGROUND_IMAGES = 4;
    const DEFAULT_SERVER_BACKGROUND_MEDIA_URL = @json($defaultServerBackgroundMediaUrl);
    const DEFAULT_SERVER_BACKGROUND_OPACITY = 0.72;
    const preconnectedOrigins = new Set();
    let imageObserver = null;

    const removeAllBackgrounds = () => {
        document.querySelectorAll(`.${BACKGROUND_MEDIA_CLASS}, .${BACKGROUND_CLASS}`).forEach((el) => {
            if (el instanceof HTMLImageElement && imageObserver) {
                imageObserver.unobserve(el);
            }

            el.remove();
        });
    };

    const isVideoBackgroundUrl = (url) => {
        if (typeof url !== 'string') {
            return false;
        }

        const withoutHash = url.split('#')[0];
        const withoutQuery = withoutHash.split('?')[0];
        const normalized = withoutQuery.trim().toLowerCase();

        return VIDEO_EXTENSIONS.some((extension) => normalized.endsWith(extension));
    };

    const isDashboard = () => window.location.pathname === DASHBOARD_PATH;

    // Cached API data so we don't refetch on every DOM mutation.
    let cacheKey = null;
    let eggBackgroundsById = new Map();
    let serverBackgroundsByIdentifier = new Map();
    let fetchInFlight = null;
    let applyScheduled = false;

    const resetCache = () => {
        cacheKey = null;
        eggBackgroundsById = new Map();
        serverBackgroundsByIdentifier = new Map();
    };

    const normalizeServerIdentifier = (value) => {
        if (typeof value !== 'string') return null;
        const trimmed = value.trim();
        return trimmed === '' ? null : trimmed;
    };

    const normalizeEggId = (value) => {
        if (typeof value === 'number' && Number.isFinite(value)) return String(value);
        if (typeof value !== 'string') return null;
        const trimmed = value.trim();
        if (trimmed === '' || Number.isNaN(Number(trimmed))) return null;
        return String(Number(trimmed));
    };

    const loadSettings = async () => {
        try {
            const r = await fetch(`${EXT_BASE}/api/settings`, {
                method: 'GET',
                headers: { 'Content-Type': 'application/json' },
            });

            if (!r.ok) return { disable_for_admins: false, user_is_admin: false };
            return await r.json();
        } catch {
            return { disable_for_admins: false, user_is_admin: false };
        }
    };

    const fetchData = async () => {
        const key = 'configured-backgrounds';

        if (cacheKey === key) return;
        if (fetchInFlight) return await fetchInFlight;

        fetchInFlight = Promise.all([
            fetch(`${EXT_BASE}/configured-egg-backgrounds`).then((r) => r.json()),
            fetch(`${EXT_BASE}/configured-server-backgrounds-effective`).then((r) => r.json()),
        ])
            .then(([configuredEggs, configuredServerBackgrounds]) => {
                cacheKey = key;
                eggBackgroundsById = new Map();
                serverBackgroundsByIdentifier = new Map();

                if (Array.isArray(configuredEggs)) {
                    for (const egg of configuredEggs) {
                        const id = normalizeEggId(egg?.id);
                        const url = egg?.image_url;
                        if (!id || typeof url !== 'string' || url.trim() === '') continue;

                        eggBackgroundsById.set(id, {
                            image_url: url,
                            opacity: egg?.opacity,
                        });
                    }
                }

                if (Array.isArray(configuredServerBackgrounds)) {
                    for (const bg of configuredServerBackgrounds) {
                        const serverIdentifier = normalizeServerIdentifier(bg?.identifier) || normalizeServerIdentifier(bg?.uuid);
                        const url = bg?.image_url;
                        if (!serverIdentifier || typeof url !== 'string' || url.trim() === '') continue;

                        serverBackgroundsByIdentifier.set(serverIdentifier, {
                            image_url: url,
                            opacity: bg?.opacity,
                        });
                    }
                }
            })
            .finally(() => {
                fetchInFlight = null;
            });

        return await fetchInFlight;
    };

    const clampOpacity = (value) => {
        const n = Number(value);
        if (!Number.isFinite(n)) return 1;
        return Math.max(0, Math.min(1, n));
    };

    const ensurePreconnect = (url) => {
        try {
            const origin = new URL(url, window.location.origin).origin;
            if (origin === window.location.origin || preconnectedOrigins.has(origin)) {
                return;
            }

            const preconnect = document.createElement('link');
            preconnect.rel = 'preconnect';
            preconnect.href = origin;
            preconnect.crossOrigin = 'anonymous';
            document.head.appendChild(preconnect);
            preconnectedOrigins.add(origin);
        } catch {
            // Ignore invalid origins.
        }
    };

    const isNearViewport = (element) => {
        const rect = element.getBoundingClientRect();
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;

        return (
            rect.bottom >= -120 &&
            rect.top <= viewportHeight + 320 &&
            rect.right >= -120 &&
            rect.left <= viewportWidth + 120
        );
    };

    const getImageObserver = () => {
        if (!('IntersectionObserver' in window)) {
            return null;
        }

        if (imageObserver) {
            return imageObserver;
        }

        imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting && entry.intersectionRatio <= 0) {
                    return;
                }

                const img = entry.target;
                if (!(img instanceof HTMLImageElement)) {
                    observer.unobserve(entry.target);
                    return;
                }

                const pendingSrc = img.dataset.pendingSrc;
                if (pendingSrc && img.getAttribute('src') !== pendingSrc) {
                    img.setAttribute('src', pendingSrc);
                }

                delete img.dataset.pendingSrc;
                observer.unobserve(img);
            });
        }, { rootMargin: IMAGE_PRELOAD_ROOT_MARGIN, threshold: 0.01 });

        return imageObserver;
    };

    const applyImageSource = (imgEl, imageUrl, eagerLoad) => {
        imgEl.loading = eagerLoad ? 'eager' : 'lazy';
        imgEl.decoding = 'async';
        imgEl.setAttribute('fetchpriority', eagerLoad ? 'high' : 'low');
        ensurePreconnect(imageUrl);

        if (eagerLoad) {
            if (imageObserver) {
                imageObserver.unobserve(imgEl);
            }

            delete imgEl.dataset.pendingSrc;
            if (imgEl.getAttribute('src') !== imageUrl) {
                imgEl.setAttribute('src', imageUrl);
            }

            return;
        }

        const observer = getImageObserver();
        if (!observer) {
            if (imgEl.getAttribute('src') !== imageUrl) {
                imgEl.setAttribute('src', imageUrl);
            }

            return;
        }

        imgEl.dataset.pendingSrc = imageUrl;
        if (imgEl.getAttribute('src')) {
            imgEl.removeAttribute('src');
        }

        observer.observe(imgEl);
    };

    const loadVideoIfNeeded = (videoEl) => {
        if (!(videoEl instanceof HTMLVideoElement)) return;
        if (!videoEl.getAttribute('src')) return;
        if (videoEl.networkState === HTMLMediaElement.NETWORK_EMPTY) {
            videoEl.load();
        }
    };

    const playBackgroundVideo = (videoEl) => {
        loadVideoIfNeeded(videoEl);
        videoEl.play().catch(() => undefined);
    };

    const pauseBackgroundVideo = (videoEl) => {
        videoEl.pause();
        try {
            videoEl.currentTime = 0;
        } catch {
            // Ignore browsers that block resetting currentTime.
        }
    };

    const detachVideoInteractionHandlers = (container) => {
        const handlers = container.__serverBackgroundVideoHandlers;
        if (!handlers) return;

        container.removeEventListener('mouseenter', handlers.play);
        container.removeEventListener('mouseleave', handlers.pause);
        container.removeEventListener('focusin', handlers.play);
        container.removeEventListener('focusout', handlers.pause);
        container.removeEventListener('touchstart', handlers.play);
        container.removeEventListener('click', handlers.play);

        delete container.__serverBackgroundVideoHandlers;
    };

    const attachVideoInteractionHandlers = (container, videoEl) => {
        detachVideoInteractionHandlers(container);

        const handlers = {
            play: () => playBackgroundVideo(videoEl),
            pause: () => pauseBackgroundVideo(videoEl),
        };

        container.addEventListener('mouseenter', handlers.play);
        container.addEventListener('mouseleave', handlers.pause);
        container.addEventListener('focusin', handlers.play);
        container.addEventListener('focusout', handlers.pause);
        container.addEventListener('touchstart', handlers.play, { passive: true });
        container.addEventListener('click', handlers.play);

        container.__serverBackgroundVideoHandlers = handlers;
    };

    const getCardIdentifier = (container) => {
        const direct = normalizeServerIdentifier(container.getAttribute('data-server-identifier'));
        if (direct) return direct;

        const href = container.getAttribute('href');
        if (!href) return null;

        try {
            const parsed = new URL(href, window.location.origin);
            const parts = parsed.pathname.split('/').filter(Boolean);
            return parts.length ? normalizeServerIdentifier(decodeURIComponent(parts[parts.length - 1])) : null;
        } catch {
            const parts = href.split('/').filter(Boolean);
            return parts.length ? normalizeServerIdentifier(decodeURIComponent(parts[parts.length - 1])) : null;
        }
    };

    const applyBackgrounds = async () => {
        if (!isDashboard()) return;

        await fetchData();

        const serverCards = Array.from(document.querySelectorAll(SERVER_CARD_SELECTOR));
        let eagerImageBudget = MAX_EAGER_BACKGROUND_IMAGES;

        serverCards.forEach((container) => {
            const identifier = getCardIdentifier(container);
            if (!identifier) return;

            const serverBg =
                serverBackgroundsByIdentifier.get(identifier) ||
                serverBackgroundsByIdentifier.get(normalizeServerIdentifier(container.getAttribute('data-server-uuid')));
            const eggId = normalizeEggId(container.getAttribute('data-server-egg-id'));
            const eggBg = eggId ? eggBackgroundsById.get(eggId) : null;
            const chosen = serverBg || eggBg || (
                DEFAULT_SERVER_BACKGROUND_MEDIA_URL
                    ? {
                        image_url: DEFAULT_SERVER_BACKGROUND_MEDIA_URL,
                        opacity: DEFAULT_SERVER_BACKGROUND_OPACITY,
                    }
                    : null
            );

            const imageUrl = chosen?.image_url;
            const existing = container.querySelector(`.${BACKGROUND_MEDIA_CLASS}, .${BACKGROUND_CLASS}`);
            const isVideoMedia = isVideoBackgroundUrl(imageUrl);

            if (!imageUrl || typeof imageUrl !== 'string') {
                if (existing) {
                    if (existing instanceof HTMLImageElement && imageObserver) {
                        imageObserver.unobserve(existing);
                    }

                    existing.remove();
                }

                detachVideoInteractionHandlers(container);
                return;
            }

            // Ensure the card creates a stacking context so the background can sit behind content.
            container.style.position = 'relative';
            container.style.overflow = 'hidden';
            container.style.zIndex = '0';
            container.style.isolation = 'isolate';
            container.style.contain = 'paint';

            const opacity = clampOpacity(chosen?.opacity);
            const bgKey = `${imageUrl}|${opacity}`;

            let bgEl = existing;
            if (
                !bgEl ||
                (isVideoMedia && !(bgEl instanceof HTMLVideoElement)) ||
                (!isVideoMedia && !(bgEl instanceof HTMLImageElement))
            ) {
                if (bgEl) {
                    if (bgEl instanceof HTMLImageElement && imageObserver) {
                        imageObserver.unobserve(bgEl);
                    }

                    bgEl.remove();
                }

                if (isVideoMedia) {
                    bgEl = document.createElement('video');
                    bgEl.className = `${BACKGROUND_MEDIA_CLASS} ${BACKGROUND_VIDEO_CLASS}`;
                    bgEl.muted = true;
                    bgEl.loop = true;
                    bgEl.autoplay = false;
                    bgEl.playsInline = true;
                    bgEl.preload = 'none';
                } else {
                    bgEl = document.createElement('img');
                    bgEl.className = `${BACKGROUND_MEDIA_CLASS} ${BACKGROUND_CLASS}`;
                    bgEl.alt = '';
                    bgEl.draggable = false;
                    bgEl.setAttribute('aria-hidden', 'true');
                }

                container.prepend(bgEl);
            }

            // Keep card content above the background layer.
            if (container.dataset.serverBackgroundLayerReady !== '1') {
                Array.from(container.children).forEach((child) => {
                    if (!(child instanceof HTMLElement) || child === bgEl || child.classList.contains('status-bar')) return;
                    if (!child.style.zIndex) child.style.zIndex = '1';
                });

                container.dataset.serverBackgroundLayerReady = '1';
            }

            bgEl.dataset.bgKey = bgKey;

            bgEl.style.opacity = String(opacity);
            bgEl.style.position = 'absolute';
            bgEl.style.top = '0';
            bgEl.style.left = '0';
            bgEl.style.right = '0';
            bgEl.style.bottom = '0';
            bgEl.style.zIndex = '0';
            bgEl.style.pointerEvents = 'none';

            if (isVideoMedia && bgEl instanceof HTMLVideoElement) {
                attachVideoInteractionHandlers(container, bgEl);
                bgEl.style.width = '100%';
                bgEl.style.height = '100%';
                bgEl.style.objectFit = 'cover';
                bgEl.style.objectPosition = 'center center';

                if (bgEl.getAttribute('src') !== imageUrl) {
                    bgEl.setAttribute('src', imageUrl);
                }

                pauseBackgroundVideo(bgEl);
            } else if (bgEl instanceof HTMLImageElement) {
                detachVideoInteractionHandlers(container);
                bgEl.style.width = '100%';
                bgEl.style.height = '100%';
                bgEl.style.objectFit = 'cover';
                bgEl.style.objectPosition = 'center center';

                const eagerLoad = eagerImageBudget > 0 && isNearViewport(container);
                if (eagerLoad) {
                    eagerImageBudget -= 1;
                }

                applyImageSource(bgEl, imageUrl, eagerLoad);
            }
        });
    };

    const scheduleApplyBackgrounds = () => {
        if (applyScheduled) return;

        applyScheduled = true;
        window.requestAnimationFrame(() => {
            applyScheduled = false;
            applyBackgrounds().catch(() => undefined);
        });
    };

    const emitNav = () => window.dispatchEvent(new Event('serverbackgrounds:navigation'));

    const patchHistory = () => {
        const wrap = (method) => {
            const original = history[method];
            if (typeof original !== 'function') return;

            history[method] = function (...args) {
                const result = original.apply(this, args);
                emitNav();
                return result;
            };
        };

        wrap('pushState');
        wrap('replaceState');
    };

    let observer = null;
    const mutationTouchesServerCards = (mutations) => {
        for (const mutation of mutations) {
            if (mutation.type !== 'childList') continue;

            const changedNodes = [...mutation.addedNodes, ...mutation.removedNodes];
            for (const node of changedNodes) {
                if (!(node instanceof Element)) continue;
                if (node.matches(SERVER_CARD_SELECTOR) || node.querySelector(SERVER_CARD_SELECTOR)) {
                    return true;
                }
            }
        }

        return false;
    };

    const attachObserver = () => {
        if (observer) return;
        const observedRoot = document.querySelector('.content-dashboard') || document.querySelector('#app');
        if (!observedRoot) return;

        observer = new MutationObserver((mutations) => {
            if (!isDashboard()) return;
            if (!mutationTouchesServerCards(mutations)) return;
            scheduleApplyBackgrounds();
        });

        observer.observe(observedRoot, { childList: true, subtree: true });
    };

    const detachObserver = () => {
        if (observer) {
            observer.disconnect();
            observer = null;
        }

        if (imageObserver) {
            imageObserver.disconnect();
            imageObserver = null;
        }
    };

    const onNavigation = () => {
        if (!isDashboard()) {
            detachObserver();
            return;
        }

        attachObserver();
        scheduleApplyBackgrounds();
    };

    (async () => {
        const settings = await loadSettings();
        const disabledForUser = settings?.disable_for_admins === true && settings?.user_is_admin === true;

        if (disabledForUser) {
            removeAllBackgrounds();
            return;
        }

        patchHistory();

        window.addEventListener('serverbackgrounds:navigation', onNavigation);
        window.addEventListener('popstate', emitNav);
        window.addEventListener('serverbackgrounds:invalidate', () => {
            resetCache();
            onNavigation();
        });

        // Listen for the Blueprint admin toggle (if present) to switch API sources.
        document.addEventListener('change', (event) => {
            const target = event.target;
            if (target instanceof HTMLInputElement && target.name === 'show_all_servers') {
                resetCache();
                removeAllBackgrounds();
                onNavigation();
            }
        });

        onNavigation();
    })();
});
</script>
<style>
.background-image {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center center;
    transform: translateZ(0);
}

.background-video {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
}
</style>
</head>
