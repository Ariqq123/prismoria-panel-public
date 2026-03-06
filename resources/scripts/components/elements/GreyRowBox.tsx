import styled from 'styled-components/macro';
import tw from 'twin.macro';

export default styled.div<{ $hoverable?: boolean }>`
    ${tw`flex rounded no-underline items-center p-4 border transition-colors duration-150 overflow-hidden`};
    color: var(--panel-text);
    background: var(--panel-surface-2);
    border-color: transparent;

    ${(props) => props.$hoverable !== false && `&:hover { border-color: var(--panel-border-strong); }`};

    & .icon {
        ${tw`rounded-full w-16 flex items-center justify-center p-3`};
        background: var(--panel-surface-1);
    }
`;
