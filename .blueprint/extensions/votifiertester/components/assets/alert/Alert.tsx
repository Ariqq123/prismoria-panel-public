import { ExclamationIcon, ShieldExclamationIcon, CheckCircleIcon, XCircleIcon } from '@heroicons/react/outline';
import React from 'react';
import classNames from 'classnames';

interface AlertProps {
    type: 'warning' | 'danger' | 'success' | 'error';
    className?: string;
    children: React.ReactNode;
}

export default ({ type, className, children }: AlertProps) => {
    return (
        <div
            className={classNames(
                'flex items-center border-l-8 text-gray-50 rounded-md shadow px-4 py-3',
                {
                    ['border-red-500 bg-red-500/25']: type === 'danger' || type === 'error',
                    ['border-yellow-500 bg-yellow-500/25']: type === 'warning',
                    ['border-green-500 bg-green-500/25']: type === 'success',
                },
                className
            )}
        >
            {type === 'danger' || type === 'error' ? (
                <ShieldExclamationIcon className={'w-6 h-6 text-red-400 mr-2'} />
            ) : type === 'warning' ? (
                <ExclamationIcon className={'w-6 h-6 text-yellow-500 mr-2'} />
            ) : (
                <CheckCircleIcon className={'w-6 h-6 text-green-400 mr-2'} />
            )}
            {children}
        </div>
    );
};
