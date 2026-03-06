import tw from 'twin.macro';
import { createGlobalStyle } from 'styled-components/macro';

export default createGlobalStyle`
    :root,
    body[data-color-mode='dark'] {
        --panel-body-bg: #171717;
        --panel-text: #e8e4de;
        --panel-text-muted: #b4aea4;
        --panel-heading: #f0ece6;
        --panel-surface-1: rgba(23, 23, 23, 0.8);
        --panel-surface-2: #242424;
        --panel-surface-3: #303030;
        --panel-border: #3f3f46;
        --panel-border-strong: #52525b;
        --panel-input-bg: #2f2f2f;
        --panel-input-border: #4b4b4b;
        --panel-input-border-hover: #636363;
        --panel-input-text: #e8e4de;
        --panel-select-arrow: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3e%3cpath fill='%23d4d4d8' d='M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z'/%3e%3c/svg%3e ");
        --panel-overlay-bg: rgba(0, 0, 0, 0.72);
        --panel-modal-bg: #242424;
        --panel-modal-close: #ece7df;
        --panel-modal-loading-overlay: rgba(120, 120, 120, 0.25);
        --panel-modal-ring: rgba(64, 64, 64, 0.52);
        --panel-dropdown-bg: #2a2a2a;
        --panel-dropdown-text: #e8e4de;
        --panel-dropdown-hover-bg: #343434;
        --panel-dropdown-hover-text: #f0ece6;
        --panel-titled-header-bg: #1d1d1d;
        --panel-titled-header-border: #303030;
        --panel-switch-track-bg: #6b7280;
        --panel-switch-track-border: #3f3f46;
        --panel-switch-knob-bg: #ece7df;
        --panel-terminal-bg: #0f0f0f;
        --panel-terminal-input-bg: #1a1a1a;
        --panel-terminal-input-text: #e8e4de;
        --panel-chip-bg: rgba(0, 0, 0, 0.25);
        --panel-chip-border: #52525b;
        --panel-magic-card-bg: linear-gradient(140deg, rgba(30, 30, 30, 0.96) 0%, rgba(20, 20, 20, 0.98) 56%, rgba(14, 14, 14, 1) 100%);
        --panel-magic-card-shadow: 0 16px 36px rgba(0, 0, 0, 0.3);
        --panel-magic-card-shadow-hover: 0 20px 44px rgba(0, 0, 0, 0.38);
        --panel-magic-card-glow: radial-gradient(circle at top right, rgba(248, 113, 113, 0.16), transparent 58%);
        --panel-magic-card-sweep: linear-gradient(120deg, rgba(248, 113, 113, 0), rgba(248, 113, 113, 0.18), rgba(251, 191, 36, 0));
        --panel-magic-border-gradient: linear-gradient(125deg, rgba(248, 113, 113, 0.44), rgba(251, 191, 36, 0.32), rgba(245, 158, 11, 0.28), rgba(248, 113, 113, 0.44));
        --panel-magic-action-sweep: linear-gradient(115deg, rgba(250, 204, 21, 0), rgba(250, 204, 21, 0.24), rgba(245, 158, 11, 0));
        --panel-magic-accent-border: rgba(248, 113, 113, 0.46);
        --panel-magic-title-gradient: linear-gradient(96deg, #fde68a, #fca5a5, #fdba74, #fca5a5);
        --panel-nav-bg: rgba(20, 20, 20, 0.95);
        --panel-nav-text: #e4e4e7;
        --panel-nav-text-active: #f0ece6;
        --panel-nav-hover-bg: rgba(63, 63, 70, 0.4);
        --panel-dock-bg: rgba(23, 23, 23, 0.78);
        --panel-dock-bg-mobile: rgba(23, 23, 23, 0.68);
        --panel-dock-border: rgba(82, 82, 91, 0.74);
        --panel-dock-border-dim: rgba(82, 82, 91, 0.42);
        --panel-dock-button-bg: rgba(36, 36, 36, 0.86);
        --panel-dock-button-border: rgba(82, 82, 91, 0.56);
        --panel-dock-button-text: #ece7df;
        --panel-accent-border: rgba(248, 113, 113, 0.86);
        --panel-accent-text: rgb(254 202 202);
        --panel-scroll-thumb-border: #444;
        --panel-scroll-thumb-fill: #444;
    }

    body[data-color-mode='light'] {
        --panel-body-bg: #efeee9;
        --panel-text: #201c18;
        --panel-text-muted: #433d35;
        --panel-heading: #151210;
        --panel-surface-1: rgba(247, 245, 241, 0.9);
        --panel-surface-2: #f7f5f1;
        --panel-surface-3: #f1eee8;
        --panel-border: #cbd5e1;
        --panel-border-strong: #94a3b8;
        --panel-input-bg: #f7f5f1;
        --panel-input-border: #cbd5e1;
        --panel-input-border-hover: #94a3b8;
        --panel-input-text: #2a2723;
        --panel-select-arrow: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3e%3cpath fill='%23334155' d='M9.293 12.95l.707.707L15.657 8l-1.414-1.414L10 10.828 5.757 6.586 4.343 8z'/%3e%3c/svg%3e ");
        --panel-overlay-bg: rgba(15, 23, 42, 0.56);
        --panel-modal-bg: #f7f5f1;
        --panel-modal-close: #2a2723;
        --panel-modal-loading-overlay: rgba(148, 163, 184, 0.28);
        --panel-modal-ring: rgba(148, 163, 184, 0.42);
        --panel-dropdown-bg: #f7f5f1;
        --panel-dropdown-text: #334155;
        --panel-dropdown-hover-bg: #f1f5f9;
        --panel-dropdown-hover-text: #0f172a;
        --panel-titled-header-bg: #f1eee8;
        --panel-titled-header-border: #cbd5e1;
        --panel-switch-track-bg: #cbd5e1;
        --panel-switch-track-border: #94a3b8;
        --panel-switch-knob-bg: #ece8e1;
        --panel-terminal-bg: #f1eee8;
        --panel-terminal-input-bg: #f7f5f1;
        --panel-terminal-input-text: #0f172a;
        --panel-chip-bg: rgba(148, 163, 184, 0.12);
        --panel-chip-border: #cbd5e1;
        --panel-magic-card-bg: linear-gradient(140deg, rgba(247, 245, 241, 0.97) 0%, rgba(241, 238, 232, 0.98) 56%, rgba(236, 232, 224, 1) 100%);
        --panel-magic-card-shadow: 0 10px 28px rgba(15, 23, 42, 0.12);
        --panel-magic-card-shadow-hover: 0 14px 32px rgba(15, 23, 42, 0.18);
        --panel-magic-card-glow: radial-gradient(circle at top right, rgba(220, 38, 38, 0.14), transparent 60%);
        --panel-magic-card-sweep: linear-gradient(120deg, rgba(220, 38, 38, 0), rgba(220, 38, 38, 0.14), rgba(59, 130, 246, 0.14));
        --panel-magic-border-gradient: linear-gradient(125deg, rgba(220, 38, 38, 0.34), rgba(217, 119, 6, 0.28), rgba(37, 99, 235, 0.28), rgba(220, 38, 38, 0.34));
        --panel-magic-action-sweep: linear-gradient(115deg, rgba(217, 119, 6, 0), rgba(217, 119, 6, 0.22), rgba(37, 99, 235, 0.18));
        --panel-magic-accent-border: rgba(220, 38, 38, 0.5);
        --panel-magic-title-gradient: linear-gradient(96deg, #ca8a04, #dc2626, #2563eb, #dc2626);
        --panel-nav-bg: rgba(247, 245, 241, 0.95);
        --panel-nav-text: #334155;
        --panel-nav-text-active: #0f172a;
        --panel-nav-hover-bg: rgba(241, 238, 232, 0.92);
        --panel-dock-bg: rgba(247, 245, 241, 0.78);
        --panel-dock-bg-mobile: rgba(247, 245, 241, 0.72);
        --panel-dock-border: rgba(148, 163, 184, 0.86);
        --panel-dock-border-dim: rgba(148, 163, 184, 0.5);
        --panel-dock-button-bg: rgba(241, 238, 232, 0.96);
        --panel-dock-button-border: rgba(148, 163, 184, 0.62);
        --panel-dock-button-text: #2a2723;
        --panel-accent-border: rgba(220, 38, 38, 0.72);
        --panel-accent-text: #dc2626;
        --panel-scroll-thumb-border: #94a3b8;
        --panel-scroll-thumb-fill: #94a3b8;
    }

    body {
        ${tw`font-sans`};
        background-color: var(--panel-body-bg);
        color: var(--panel-text);
        letter-spacing: 0.015em;
        transition: background-color 260ms cubic-bezier(0.22, 1, 0.36, 1), color 220ms ease;
    }

    #NavigationBar {
        background: var(--panel-nav-bg);
        backdrop-filter: blur(10px);
        border-bottom: 1px solid var(--panel-border);
    }

    #logo a {
        color: var(--panel-nav-text) !important;
    }

    #logo a:hover {
        color: var(--panel-nav-text-active) !important;
    }

    h1, h2, h3, h4, h5, h6 {
        ${tw`font-medium tracking-normal font-header`};
        color: var(--panel-heading);
    }

    p {
        ${tw`leading-snug font-sans`};
        color: var(--panel-text);
    }

    a {
        color: inherit;
    }

    form {
        ${tw`m-0`};
    }

    textarea, select, input, button, button:focus, button:focus-visible {
        ${tw`outline-none`};
    }

    input[type=number]::-webkit-outer-spin-button,
    input[type=number]::-webkit-inner-spin-button {
        -webkit-appearance: none !important;
        margin: 0;
    }

    input[type=number] {
        -moz-appearance: textfield !important;
    }

    /* Scroll Bar Style */
    ::-webkit-scrollbar {
        background: none;
        width: 16px;
        height: 16px;
    }

    ::-webkit-scrollbar-thumb {
        border: solid 0 rgb(0 0 0 / 0%);
        border-right-width: 4px;
        border-left-width: 4px;
        -webkit-border-radius: 9px 4px;
        -webkit-box-shadow: inset 0 0 0 1px var(--panel-scroll-thumb-border), inset 0 0 0 4px var(--panel-scroll-thumb-fill);
    }

    ::-webkit-scrollbar-track-piece {
        margin: 4px 0;
    }

    ::-webkit-scrollbar-thumb:horizontal {
        border-right-width: 0;
        border-left-width: 0;
        border-top-width: 4px;
        border-bottom-width: 4px;
        -webkit-border-radius: 4px 9px;
    }

    ::-webkit-scrollbar-corner {
        background: transparent;
    }

    body[data-color-mode='light'] [class*='bg-neutral-900'],
    body[data-color-mode='light'] [class*='bg-neutral-800'],
    body[data-color-mode='light'] [class*='bg-neutral-700'],
    body[data-color-mode='light'] [class*='bg-neutral-600'] {
        background-color: var(--panel-surface-2) !important;
    }

    body[data-color-mode='light'] [class*='text-neutral-100'],
    body[data-color-mode='light'] [class*='text-neutral-200'] {
        color: var(--panel-heading) !important;
    }

    body[data-color-mode='light'] [class*='text-neutral-300'],
    body[data-color-mode='light'] [class*='text-neutral-400'] {
        color: var(--panel-text) !important;
    }

    body[data-color-mode='light'] [class*='text-neutral-500'],
    body[data-color-mode='light'] [class*='text-neutral-600'] {
        color: var(--panel-text-muted) !important;
    }

    body[data-color-mode='light'] [class*='text-gray-100'],
    body[data-color-mode='light'] [class*='text-gray-200'],
    body[data-color-mode='light'] [class*='text-gray-300'],
    body[data-color-mode='light'] [class*='text-gray-400'] {
        color: var(--panel-text) !important;
    }

    body[data-color-mode='light'] [class*='text-gray-500'],
    body[data-color-mode='light'] [class*='text-gray-600'],
    body[data-color-mode='light'] [class*='text-gray-700'],
    body[data-color-mode='light'] [class*='text-gray-800'],
    body[data-color-mode='light'] [class*='text-gray-900'] {
        color: var(--panel-text-muted) !important;
    }

    body[data-color-mode='light'] [class*='border-neutral-'] {
        border-color: var(--panel-border) !important;
    }

    body[data-color-mode='light'] [class*='border-gray-'] {
        border-color: var(--panel-border) !important;
    }

    body[data-color-mode='light'] [class*='bg-black'] {
        background-color: var(--panel-surface-2) !important;
    }

    body[data-color-mode='light'] [class*='bg-gray-'] {
        background-color: var(--panel-surface-2) !important;
    }

    body[data-color-mode='light'] [class*='text-white'] {
        color: var(--panel-text) !important;
    }

    body[data-color-mode='dark'] [class*='text-white'],
    body[data-color-mode='dark'] [class*='text-neutral-100'] {
        color: var(--panel-heading) !important;
    }

    body[data-color-mode='dark'] [class*='text-neutral-200'],
    body[data-color-mode='dark'] [class*='text-neutral-300'] {
        color: var(--panel-text) !important;
    }

    body[data-color-mode='dark'] [class*='text-neutral-400'],
    body[data-color-mode='dark'] [class*='text-neutral-500'],
    body[data-color-mode='dark'] [class*='text-neutral-600'] {
        color: var(--panel-text-muted) !important;
    }

    body[data-color-mode='dark'] [class*='bg-neutral-900'] {
        background-color: var(--panel-surface-2) !important;
    }

    body[data-color-mode='dark'] [class*='bg-neutral-800'],
    body[data-color-mode='dark'] [class*='bg-neutral-700'],
    body[data-color-mode='dark'] [class*='bg-neutral-600'] {
        background-color: var(--panel-surface-3) !important;
    }

    body[data-color-mode='dark'] [class*='border-neutral-'] {
        border-color: var(--panel-border) !important;
    }

    body[data-color-mode='dark'] [class*='text-gray-'] {
        color: var(--panel-text-muted) !important;
    }

    body[data-color-mode='dark'] [class*='bg-gray-'] {
        background-color: var(--panel-surface-3) !important;
    }

    body[data-color-mode='dark'] [class*='border-gray-'] {
        border-color: var(--panel-border) !important;
    }

    body[data-color-mode='light'] .xterm .xterm-viewport,
    body[data-color-mode='light'] .xterm .xterm-screen {
        background-color: var(--panel-terminal-bg) !important;
    }

    body[data-color-mode='dark'] .xterm .xterm-viewport,
    body[data-color-mode='dark'] .xterm .xterm-screen {
        background-color: var(--panel-terminal-bg) !important;
    }
`;
