import styled from 'styled-components/macro';
import tw from 'twin.macro';

export const Section = styled.section`
    ${tw`rounded-lg border border-neutral-800 bg-neutral-900 shadow-md overflow-hidden`};
`;

export const SectionHeader = styled.div`
    ${tw`px-4 md:px-5 py-3 md:py-4 bg-neutral-800 border-b border-neutral-700 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3`};
`;

export const SectionTitle = styled.h3`
    ${tw`text-sm md:text-base font-semibold uppercase tracking-wide text-neutral-100`};
`;

export const SectionBadge = styled.span`
    ${tw`text-xs font-medium px-2 py-1 rounded bg-neutral-700 text-neutral-200 whitespace-nowrap`};
`;

export const SectionBody = styled.div`
    ${tw`p-3 md:p-4 space-y-3`};
`;

export const EmptyState = styled.p`
    ${tw`text-center text-sm text-neutral-400 py-8`};
`;

interface ListItemProps {
    $selected?: boolean;
}

export const ListItem = styled.div<ListItemProps>`
    ${tw`rounded-md border border-neutral-700 bg-neutral-700/70 px-3 py-3 md:px-4 transition-colors duration-150`};

    ${({ $selected }) =>
        $selected
            ? tw`border-red-400/80 bg-red-500/10`
            : tw`hover:border-neutral-600 hover:bg-neutral-700/90`};
`;

export const Code = styled.code`
    ${tw`font-mono text-xs md:text-sm py-1 px-2 bg-neutral-900 rounded inline-block break-all max-w-full`};
`;

export const Label = styled.span`
    ${tw`uppercase text-xs mt-1 text-neutral-400 block select-none tracking-wide`};
`;

export const Avatar = styled.img`
    ${tw`w-10 h-10 md:w-12 md:h-12 rounded border border-neutral-800`};
`;

export const Actions = styled.div`
    ${tw`w-full flex flex-wrap items-center gap-2 pt-3 mt-3 border-t border-neutral-700 sm:justify-end`};
`;
