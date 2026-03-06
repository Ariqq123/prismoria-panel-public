import React from 'react';
import Icon from '@/components/elements/Icon';
import { IconDefinition } from '@fortawesome/free-solid-svg-icons';
import classNames from 'classnames';
import styles from './style.module.css';
import useFitText from 'use-fit-text';
import CopyOnClick from '@/components/elements/CopyOnClick';

interface StatBlockProps {
    title: string;
    copyOnClick?: string;
    color?: string | undefined;
    icon: IconDefinition;
    children: React.ReactNode;
    className?: string;
}

export default ({ title, copyOnClick, icon, color, className, children }: StatBlockProps) => {
    const { fontSize, ref } = useFitText({ minFontSize: 8, maxFontSize: 500 });
    const defaultIconColor = !color || color === 'bg-gray-700' ? 'var(--panel-text-muted)' : 'var(--panel-heading)';

    return (
        <CopyOnClick text={copyOnClick}>
            <div
                className={classNames(styles.stat_block, className)}
                style={{
                    background: 'var(--panel-surface-2)',
                    border: '1px solid var(--panel-border)',
                }}
            >
                <div className={styles.status_bar} style={{ background: 'var(--panel-border-strong)' }} />
                <div className={styles.icon} style={{ background: 'var(--panel-surface-3)' }}>
                    <Icon icon={icon} style={{ color: defaultIconColor }} />
                </div>
                <div className={'flex flex-col justify-center overflow-hidden w-full'}>
                    <p className={'font-header leading-tight text-xs md:text-sm'} style={{ color: 'var(--panel-text-muted)' }}>
                        {title}
                    </p>
                    <div
                        ref={ref}
                        className={'h-[1.75rem] w-full font-semibold truncate'}
                        style={{ fontSize, color: 'var(--panel-heading)' }}
                    >
                        {children}
                    </div>
                </div>
            </div>
        </CopyOnClick>
    );
};
