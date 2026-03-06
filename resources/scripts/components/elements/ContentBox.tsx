import React from 'react';
import FlashMessageRender from '@/components/FlashMessageRender';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import tw from 'twin.macro';

type Props = Readonly<
    React.DetailedHTMLProps<React.HTMLAttributes<HTMLDivElement>, HTMLDivElement> & {
        title?: React.ReactNode;
        headerAction?: React.ReactNode;
        borderColor?: string;
        showFlashes?: string | boolean;
        showLoadingOverlay?: boolean;
    }
>;

const ContentBox = ({ title, headerAction, borderColor, showFlashes, showLoadingOverlay, children, ...props }: Props) => (
    <div {...props}>
        {title &&
            (!headerAction ? (
                <h2 css={tw`mb-4 px-4 text-2xl`} style={{ color: 'var(--panel-heading)' }}>
                    {title}
                </h2>
            ) : (
                <div css={tw`mb-4 px-4 flex items-center justify-between flex-wrap gap-2`}>
                    <h2 css={tw`text-2xl`} style={{ color: 'var(--panel-heading)' }}>
                        {title}
                    </h2>
                    <div>{headerAction}</div>
                </div>
            ))}
        {showFlashes && (
            <FlashMessageRender byKey={typeof showFlashes === 'string' ? showFlashes : undefined} css={tw`mb-4`} />
        )}
        <div
            css={[tw`p-4 rounded shadow-lg relative`, !!borderColor && tw`border-t-4`]}
            style={{ backgroundColor: 'var(--panel-surface-2)', color: 'var(--panel-text)' }}
        >
            <SpinnerOverlay visible={showLoadingOverlay || false} />
            {children}
        </div>
    </div>
);

export default ContentBox;
