import React from 'react';
import tw from 'twin.macro';
import styled, { keyframes } from 'styled-components/macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faServer } from '@fortawesome/free-solid-svg-icons';

interface Props {
    title?: string;
    message?: string;
    compact?: boolean;
}

const pulse = keyframes`
    0%, 100% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.35);
    }
    50% {
        transform: scale(1.06);
        box-shadow: 0 0 0 12px rgba(239, 68, 68, 0);
    }
`;

const slide = keyframes`
    0% {
        transform: translateX(-105%);
    }
    100% {
        transform: translateX(105%);
    }
`;

const Orb = styled.div`
    ${tw`w-11 h-11 rounded-full flex items-center justify-center text-red-200 bg-red-500/20 border border-red-400/40`};
    animation: ${pulse} 1.45s cubic-bezier(0.34, 1.56, 0.64, 1) infinite;
`;

const Track = styled.div`
    ${tw`mt-4 h-1.5 w-full rounded-full overflow-hidden bg-neutral-700/70`};
`;

const Trail = styled.div`
    ${tw`h-full w-1/3 rounded-full bg-gradient-to-r from-red-600/80 via-red-400 to-red-600/80`};
    animation: ${slide} 1.35s cubic-bezier(0.4, 0, 0.2, 1) infinite;
`;

const LoadingPage = ({
    title = 'Loading Panel',
    message = 'Preparing your panel experience...',
    compact = false,
}: Props) => (
    <div css={[tw`w-full flex justify-center items-center px-4`, compact ? tw`py-14` : tw`min-h-[68vh]`]}>
        <div css={tw`w-full max-w-xl rounded-2xl border border-neutral-700/80 bg-neutral-900/80 backdrop-blur-sm shadow-2xl px-7 py-6`}>
            <div css={tw`flex items-center gap-4`}>
                <Orb>
                    <FontAwesomeIcon icon={faServer} />
                </Orb>
                <div css={tw`min-w-0`}>
                    <p css={tw`text-base md:text-lg font-semibold text-neutral-100 leading-tight`}>{title}</p>
                    <p css={tw`text-sm text-neutral-400 mt-1`}>{message}</p>
                </div>
            </div>
            <Track>
                <Trail />
            </Track>
        </div>
    </div>
);

export default LoadingPage;
