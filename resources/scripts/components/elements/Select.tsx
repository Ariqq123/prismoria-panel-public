import styled, { css } from 'styled-components/macro';
import tw from 'twin.macro';

interface Props {
    hideDropdownArrow?: boolean;
}

const Select = styled.select<Props>`
    ${tw`shadow-none block p-3 pr-8 rounded border w-full text-sm transition-colors duration-150 ease-linear`};
    background-color: var(--panel-input-bg);
    border-color: var(--panel-input-border);
    color: var(--panel-input-text);

    &,
    &:hover:not(:disabled),
    &:focus {
        ${tw`outline-none`};
    }

    -webkit-appearance: none;
    -moz-appearance: none;
    background-size: 1rem;
    background-repeat: no-repeat;
    background-position-x: calc(100% - 0.75rem);
    background-position-y: center;

    &::-ms-expand {
        display: none;
    }

    ${(props) =>
        !props.hideDropdownArrow &&
        css`
            background-image: var(--panel-select-arrow);

            &:hover:not(:disabled),
            &:focus {
                border-color: var(--panel-input-border-hover);
            }
        `};
`;

export default Select;
