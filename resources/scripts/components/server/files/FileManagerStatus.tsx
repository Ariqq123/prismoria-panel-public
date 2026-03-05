import React, { useContext, useEffect, useMemo } from 'react';
import { ServerContext } from '@/state/server';
import { CloudUploadIcon, XIcon } from '@heroicons/react/solid';
import asDialog from '@/hoc/asDialog';
import { Dialog, DialogWrapperContext } from '@/components/elements/dialog';
import { Button } from '@/components/elements/button/index';
import Tooltip from '@/components/elements/tooltip/Tooltip';
import Code from '@/components/elements/Code';
import { useSignal } from '@preact/signals-react';

const svgProps = {
    cx: 16,
    cy: 16,
    r: 14,
    strokeWidth: 3,
    fill: 'none',
    stroke: 'currentColor',
};

const MAX_RING = 28 * Math.PI;
const clampProgress = (value: number): number => Math.min(100, Math.max(0, Number.isFinite(value) ? value : 0));

const Spinner = ({
    progress,
    spinning,
    className,
}: {
    progress: number;
    spinning?: boolean;
    className?: string;
}) => {
    const safeProgress = clampProgress(progress);

    return (
        <svg viewBox={'0 0 32 32'} className={className}>
            <circle {...svgProps} className={'opacity-25'} />
            <circle
                {...svgProps}
                stroke={'white'}
                strokeDasharray={MAX_RING}
                className={
                    spinning
                        ? 'origin-[50%_50%] animate-spin'
                        : 'rotate-[-90deg] origin-[50%_50%] transition-[stroke-dashoffset] duration-300'
                }
                style={
                    spinning
                        ? { strokeDashoffset: MAX_RING * 0.65, strokeDasharray: `${MAX_RING * 0.35} ${MAX_RING}` }
                        : { strokeDashoffset: ((100 - safeProgress) / 100) * MAX_RING }
                }
            />
        </svg>
    );
};

const FileUploadList = () => {
    const { close } = useContext(DialogWrapperContext);
    const cancelFileUpload = ServerContext.useStoreActions((actions) => actions.files.cancelFileUpload);
    const clearFileUploads = ServerContext.useStoreActions((actions) => actions.files.clearFileUploads);
    const isPreparingUpload = ServerContext.useStoreState((state) => state.files.isPreparingUpload);
    const expectedUploadCount = ServerContext.useStoreState((state) => state.files.expectedUploadCount);
    const expectedUploadBytes = ServerContext.useStoreState((state) => state.files.expectedUploadBytes);
    const completedUploadCount = ServerContext.useStoreState((state) => state.files.completedUploadCount);
    const completedUploadBytes = ServerContext.useStoreState((state) => state.files.completedUploadBytes);
    const uploads = ServerContext.useStoreState((state) =>
        Object.entries(state.files.uploads).sort(([a], [b]) => a.localeCompare(b))
    );
    const aggregate = useMemo(() => {
        const activeLoaded = uploads.reduce((count, [, file]) => count + file.loaded, 0);
        const activeTotal = uploads.reduce((count, [, file]) => count + file.total, 0);
        const activeCount = uploads.length;
        const expectedCount = Math.max(expectedUploadCount, completedUploadCount + activeCount);
        const expectedBytes = Math.max(expectedUploadBytes, completedUploadBytes + activeTotal);
        const completedCount = Math.min(expectedCount, completedUploadCount);
        const completedBytes = Math.min(expectedBytes, completedUploadBytes);
        const progress = expectedBytes > 0 ? ((completedBytes + activeLoaded) / expectedBytes) * 100 : 0;

        return { expectedCount, completedCount, progress };
    }, [uploads, expectedUploadCount, expectedUploadBytes, completedUploadCount, completedUploadBytes]);

    return (
        <div className={'space-y-2 mt-6'}>
            <div className={'rounded bg-gray-800/80 p-3 mb-3'}>
                <div className={'flex items-center justify-between text-xs text-neutral-300 mb-2'}>
                    <span>{isPreparingUpload ? 'Preparing folders and files...' : 'Uploading files...'}</span>
                    <span>
                        {aggregate.completedCount}/{aggregate.expectedCount} completed
                    </span>
                </div>
                <div className={'h-2 w-full rounded bg-neutral-700 overflow-hidden'}>
                    <div
                        className={
                            isPreparingUpload
                                ? 'h-full w-2/5 rounded bg-blue-400/80 animate-pulse'
                                : 'h-full rounded bg-blue-400 transition-[width] duration-300'
                        }
                        style={isPreparingUpload ? undefined : { width: `${clampProgress(aggregate.progress)}%` }}
                    />
                </div>
            </div>
            {uploads.map(([name, file]) => (
                <div key={name} className={'flex items-start space-x-3 bg-gray-700 p-3 rounded'}>
                    <Tooltip content={`${Math.floor(clampProgress((file.loaded / Math.max(1, file.total)) * 100))}%`} placement={'left'}>
                        <div className={'flex-shrink-0'}>
                            <Spinner progress={(file.loaded / Math.max(1, file.total)) * 100} className={'w-6 h-6'} />
                        </div>
                    </Tooltip>
                    <div className={'flex-1 min-w-0'}>
                        <Code className={'block w-full whitespace-normal break-all leading-5'}>{name}</Code>
                        <div className={'mt-1 h-1.5 rounded bg-neutral-800 overflow-hidden'}>
                            <div
                                className={'h-full rounded bg-blue-400 transition-[width] duration-300'}
                                style={{ width: `${clampProgress((file.loaded / Math.max(1, file.total)) * 100)}%` }}
                            />
                        </div>
                    </div>
                    <button
                        onClick={cancelFileUpload.bind(this, name)}
                        className={'text-gray-500 hover:text-gray-200 transition-colors duration-75 mt-0.5'}
                    >
                        <XIcon className={'w-5 h-5'} />
                    </button>
                </div>
            ))}
            <Dialog.Footer>
                <Button.Danger variant={Button.Variants.Secondary} onClick={() => clearFileUploads()}>
                    Cancel Uploads
                </Button.Danger>
                <Button.Text onClick={close}>Close</Button.Text>
            </Dialog.Footer>
        </div>
    );
};

const FileUploadListDialog = asDialog({
    title: 'File Uploads',
    description: 'Track file and folder upload progress in real-time.',
})(FileUploadList);

export default () => {
    const open = useSignal(false);

    const count = ServerContext.useStoreState((state) => Object.keys(state.files.uploads).length);
    const isPreparingUpload = ServerContext.useStoreState((state) => state.files.isPreparingUpload);
    const expectedUploadCount = ServerContext.useStoreState((state) => state.files.expectedUploadCount);
    const expectedUploadBytes = ServerContext.useStoreState((state) => state.files.expectedUploadBytes);
    const completedUploadCount = ServerContext.useStoreState((state) => state.files.completedUploadCount);
    const progress = ServerContext.useStoreState((state) => {
        const activeLoaded = Object.values(state.files.uploads).reduce((acc, file) => acc + file.loaded, 0);
        const activeTotal = Object.values(state.files.uploads).reduce((acc, file) => acc + file.total, 0);
        const expectedBytes = Math.max(state.files.expectedUploadBytes, state.files.completedUploadBytes + activeTotal);
        const uploadedBytes = Math.min(expectedBytes, state.files.completedUploadBytes) + activeLoaded;

        return {
            uploadedBytes,
            expectedBytes,
        };
    });
    const progressPercent = progress.expectedBytes > 0 ? (progress.uploadedBytes / progress.expectedBytes) * 100 : 0;
    const expectedCount = Math.max(expectedUploadCount, completedUploadCount + count);
    const completedCount = Math.min(expectedCount, completedUploadCount);
    const statusText = isPreparingUpload
        ? `Preparing ${expectedCount || count || 1} file(s)...`
        : `${completedCount}/${expectedCount} completed${expectedUploadBytes > 0 ? ` (${Math.floor(clampProgress(progressPercent))}%)` : ''}`;

    useEffect(() => {
        if (count === 0 && !isPreparingUpload) {
            open.value = false;
        }
    }, [count, isPreparingUpload]);

    return (
        <>
            {(count > 0 || isPreparingUpload) && (
                <Tooltip content={`${statusText} Click to view.`}>
                    <button
                        className={'flex items-center justify-center w-10 h-10'}
                        onClick={() => (open.value = true)}
                    >
                        <Spinner
                            progress={progressPercent}
                            spinning={isPreparingUpload || progress.expectedBytes === 0}
                            className={'w-8 h-8'}
                        />
                        <CloudUploadIcon className={'h-3 absolute mx-auto animate-pulse'} />
                    </button>
                </Tooltip>
            )}
            <FileUploadListDialog open={open.value} onClose={() => (open.value = false)} />
        </>
    );
};
