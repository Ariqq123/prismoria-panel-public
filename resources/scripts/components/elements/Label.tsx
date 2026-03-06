import styled from 'styled-components/macro';
import tw from 'twin.macro';

const Label = styled.label<{ isLight?: boolean }>`
    ${tw`block text-xs uppercase mb-1 sm:mb-2`};
    color: var(--panel-text);
    opacity: 0.9;

    ${(props) =>
        props.isLight &&
        `
            color: var(--panel-text-muted);
            opacity: 1;
        `};
`;

export default Label;
