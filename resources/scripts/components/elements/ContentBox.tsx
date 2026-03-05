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
                <h2 css={tw`text-neutral-300 mb-4 px-4 text-2xl`}>{title}</h2>
            ) : (
                <div css={tw`mb-4 px-4 flex items-center justify-between flex-wrap gap-2`}>
                    <h2 css={tw`text-neutral-300 text-2xl`}>{title}</h2>
                    <div>{headerAction}</div>
                </div>
            ))}
        {showFlashes && (
            <FlashMessageRender byKey={typeof showFlashes === 'string' ? showFlashes : undefined} css={tw`mb-4`} />
        )}
        <div css={[tw`bg-neutral-700 p-4 rounded shadow-lg relative`, !!borderColor && tw`border-t-4`]}>
            <SpinnerOverlay visible={showLoadingOverlay || false} />
            {children}
        </div>
    </div>
);

export default ContentBox;
