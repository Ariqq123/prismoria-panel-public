import * as React from 'react';
import tw from 'twin.macro';
import styled from 'styled-components/macro';

export type FlashMessageType = 'success' | 'info' | 'warning' | 'error';

interface Props {
    title?: string;
    children: string;
    type?: FlashMessageType;
}

const styling = (type?: FlashMessageType): string => {
    switch (type) {
        case 'error':
            return `
                background: rgba(127, 29, 29, 0.45);
                border-color: rgba(248, 113, 113, 0.55);
                color: rgb(254 226 226);
            `;
        case 'info':
            return `
                background: rgba(30, 64, 175, 0.35);
                border-color: rgba(96, 165, 250, 0.55);
                color: rgb(219 234 254);
            `;
        case 'success':
            return `
                background: rgba(20, 83, 45, 0.35);
                border-color: rgba(74, 222, 128, 0.45);
                color: rgb(220 252 231);
            `;
        case 'warning':
            return `
                background: rgba(120, 53, 15, 0.35);
                border-color: rgba(251, 191, 36, 0.5);
                color: rgb(254 243 199);
            `;
        default:
            return `
                background: var(--panel-surface-1);
                border-color: var(--panel-border);
                color: var(--panel-text);
            `;
    }
};

const getBackground = (type?: FlashMessageType): string => {
    switch (type) {
        case 'error':
            return 'rgb(220 38 38)';
        case 'info':
            return 'rgb(37 99 235)';
        case 'success':
            return 'rgb(22 163 74)';
        case 'warning':
            return 'rgb(217 119 6)';
        default:
            return 'var(--panel-surface-3)';
    }
};

const Container = styled.div<{ $type?: FlashMessageType }>`
    ${tw`p-2 border items-center leading-normal rounded flex w-full text-sm`};
    ${(props) => styling(props.$type)};
`;
Container.displayName = 'MessageBox.Container';

const MessageBox = ({ title, children, type }: Props) => (
    <Container css={tw`lg:inline-flex`} $type={type} role={'alert'}>
        {title && (
            <span
                className={'title'}
                css={tw`flex rounded-full uppercase px-2 py-1 text-xs font-bold mr-3 leading-none text-white`}
                style={{ background: getBackground(type) }}
            >
                {title}
            </span>
        )}
        <span css={tw`mr-2 text-left flex-auto`}>{children}</span>
    </Container>
);
MessageBox.displayName = 'MessageBox';

export default MessageBox;
