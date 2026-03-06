import styled from 'styled-components/macro';
import tw from 'twin.macro';

export const Section = styled.section`
    ${tw`rounded-lg border shadow-md overflow-hidden`};
    border-color: var(--panel-border);
    background: var(--panel-surface-2);
`;

export const SectionHeader = styled.div`
    ${tw`px-4 md:px-5 py-3 md:py-4 border-b flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3`};
    border-color: var(--panel-border);
    background: var(--panel-surface-3);
`;

export const SectionTitle = styled.h3`
    ${tw`text-sm md:text-base font-semibold uppercase tracking-wide`};
    color: var(--panel-heading);
`;

export const SectionBadge = styled.span`
    ${tw`text-xs font-medium px-2 py-1 rounded whitespace-nowrap`};
    background: var(--panel-chip-bg);
    color: var(--panel-text);
    border: 1px solid var(--panel-chip-border);
`;

export const SectionBody = styled.div`
    ${tw`p-3 md:p-4 space-y-3`};
`;

export const EmptyState = styled.p`
    ${tw`text-center text-sm py-8`};
    color: var(--panel-text-muted);
`;

interface ListItemProps {
    $selected?: boolean;
}

export const ListItem = styled.div<ListItemProps>`
    ${tw`rounded-md border px-3 py-3 md:px-4 transition-colors duration-150`};
    border-color: var(--panel-border);
    background: var(--panel-surface-3);

    ${({ $selected }) =>
        $selected
            ? tw`border-red-400/80 bg-red-500/10`
            : tw`hover:border-red-400/30`};
`;

export const Code = styled.code`
    ${tw`font-mono text-xs md:text-sm py-1 px-2 rounded inline-block break-all max-w-full`};
    background: var(--panel-surface-3);
    color: var(--panel-text);
    border: 1px solid var(--panel-border);
`;

export const Label = styled.span`
    ${tw`uppercase text-xs mt-1 block select-none tracking-wide`};
    color: var(--panel-text-muted);
`;

export const Avatar = styled.img`
    ${tw`w-10 h-10 md:w-12 md:h-12 rounded border`};
    border-color: var(--panel-border);
`;

export const Actions = styled.div`
    ${tw`w-full flex flex-wrap items-center gap-2 pt-3 mt-3 border-t sm:justify-end`};
    border-color: var(--panel-border);
`;
