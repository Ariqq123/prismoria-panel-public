import styled, { css } from 'styled-components/macro';
import tw from 'twin.macro';

export interface Props {
    isLight?: boolean;
    hasError?: boolean;
}

const light = css<Props>`
    background-color: var(--panel-surface-2);
    border-color: var(--panel-border);
    color: var(--panel-input-text);

    &:focus {
        border-color: var(--panel-border-strong);
    }

    &:disabled {
        background-color: var(--panel-surface-3);
        border-color: var(--panel-border);
    }
`;

const checkboxStyle = css<Props>`
    ${tw`cursor-pointer appearance-none inline-block align-middle select-none flex-shrink-0 w-4 h-4 text-primary-400 border rounded-sm`};
    background-color: var(--panel-input-bg);
    border-color: var(--panel-input-border);
    color-adjust: exact;
    background-origin: border-box;
    transition: all 75ms linear, box-shadow 25ms linear;

    &:checked {
        ${tw`border-transparent bg-no-repeat bg-center`};
        background-image: url("data:image/svg+xml,%3csvg viewBox='0 0 16 16' fill='white' xmlns='http://www.w3.org/2000/svg'%3e%3cpath d='M5.707 7.293a1 1 0 0 0-1.414 1.414l2 2a1 1 0 0 0 1.414 0l4-4a1 1 0 0 0-1.414-1.414L7 8.586 5.707 7.293z'/%3e%3c/svg%3e");
        background-color: currentColor;
        background-size: 100% 100%;
    }

    &:focus {
        ${tw`outline-none border-primary-300`};
        box-shadow: 0 0 0 1px rgba(9, 103, 210, 0.25);
    }
`;

const inputStyle = css<Props>`
    // Reset to normal styling.
    resize: none;
    ${tw`appearance-none outline-none w-full min-w-0`};
    ${tw`p-3 border-2 rounded text-sm transition-all duration-150`};
    ${tw`shadow-none focus:ring-0`};
    background-color: var(--panel-input-bg);
    border-color: var(--panel-input-border);
    color: var(--panel-input-text);

    &:hover:not(:disabled):not(:read-only) {
        border-color: var(--panel-input-border-hover);
    }

    & + .input-help {
        ${tw`mt-1 text-xs`};
        color: ${(props) => (props.hasError ? 'rgb(254 202 202)' : 'var(--panel-text-muted)')};
    }

    &:required,
    &:invalid {
        ${tw`shadow-none`};
    }

    &:not(:disabled):not(:read-only):focus {
        ${tw`shadow-md border-primary-300 ring-2 ring-primary-400 ring-opacity-50`};
        ${(props) => props.hasError && tw`border-red-300 ring-red-200`};
    }

    &:disabled {
        ${tw`opacity-75`};
    }

    ${(props) => props.isLight && light};
    ${(props) =>
        props.hasError &&
        css`
            color: rgb(254 226 226);
            border-color: rgb(248 113 113);

            &:hover:not(:disabled):not(:read-only) {
                border-color: rgb(252 165 165);
            }
        `};
`;

const Input = styled.input<Props>`
    &:not([type='checkbox']):not([type='radio']) {
        ${inputStyle};
    }

    &[type='checkbox'],
    &[type='radio'] {
        ${checkboxStyle};

        &[type='radio'] {
            ${tw`rounded-full`};
        }
    }
`;
const Textarea = styled.textarea<Props>`
    ${inputStyle}
`;

export { Textarea };
export default Input;
