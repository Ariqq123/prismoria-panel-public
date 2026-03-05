import React from 'react';
import styled, { keyframes } from 'styled-components/macro';
import tw from 'twin.macro';

interface AnimatedGradientTextProps extends React.HTMLAttributes<HTMLSpanElement> {
    speed?: number;
    colorFrom?: string;
    colorTo?: string;
}

const shift = keyframes`
    0% {
        background-position: 0% 50%;
    }
    50% {
        background-position: 100% 50%;
    }
    100% {
        background-position: 0% 50%;
    }
`;

const AnimatedText = styled.span<{ $speed: number; $from: string; $to: string }>`
    ${tw`inline-block bg-clip-text text-transparent`};
    background-image: linear-gradient(92deg, ${({ $from }) => $from}, ${({ $to }) => $to}, ${({ $from }) => $from});
    background-size: 280% 100%;
    animation: ${shift} ${({ $speed }) => Math.max(1.5, 5 / Math.max($speed, 0.2))}s cubic-bezier(0.22, 1, 0.36, 1) infinite;

    @media (prefers-reduced-motion: reduce) {
        animation: none;
        background-position: 50% 50%;
    }
`;

const AnimatedGradientText: React.FC<AnimatedGradientTextProps> = ({
    speed = 1,
    colorFrom = '#fecaca',
    colorTo = '#ef4444',
    children,
    ...props
}) => (
    <AnimatedText $speed={speed} $from={colorFrom} $to={colorTo} {...props}>
        {children}
    </AnimatedText>
);

export default AnimatedGradientText;

