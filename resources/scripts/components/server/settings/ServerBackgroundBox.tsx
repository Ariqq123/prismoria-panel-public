import React, { useEffect, useMemo, useRef, useState } from 'react';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import Label from '@/components/elements/Label';
import Input from '@/components/elements/Input';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import { Button } from '@/components/elements/button';
import tw from 'twin.macro';
import { Actions, useStoreActions, useStoreState } from 'easy-peasy';
import { ApplicationStore } from '@/state';
import { httpErrorToHuman } from '@/api/http';
import getServerBackgroundPreference from '@/api/server/getServerBackgroundPreference';
import setServerBackgroundPreference from '@/api/server/setServerBackgroundPreference';
import uploadServerBackgroundPreference from '@/api/server/uploadServerBackgroundPreference';
import { ServerContext } from '@/state/server';
import {
    clearPanelBackgroundPreference,
    getPanelBackgroundPreference,
    setPanelBackgroundPreference,
} from '@/lib/panelBackgroundPreference';

const MAX_URL_LENGTH = 2048;
const MAX_UPLOAD_SIZE_BYTES = 50 * 1024 * 1024;
const VIDEO_EXTENSIONS = ['.mp4', '.webm'];

const isVideoBackgroundUrl = (url: string): boolean => {
    const withoutHash = url.split('#')[0];
    const withoutQuery = withoutHash.split('?')[0];
    const normalized = withoutQuery.trim().toLowerCase();

    return VIDEO_EXTENSIONS.some((extension) => normalized.endsWith(extension));
};

export default () => {
    const serverUuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const serverRouteIdentifier = ServerContext.useStoreState((state) => state.server.data!.id);
    const userUuid = useStoreState((state: ApplicationStore) => state.user.data?.uuid);
    const fileInputRef = useRef<HTMLInputElement | null>(null);
    const [imageUrl, setImageUrl] = useState('');
    const [savedImageUrl, setSavedImageUrl] = useState('');
    const [selectedFile, setSelectedFile] = useState<File | null>(null);
    const [syncPanelBackgroundOnSave, setSyncPanelBackgroundOnSave] = useState(false);
    const [panelBackgroundImageUrl, setPanelBackgroundImageUrl] = useState('');
    const [isPanelBackgroundActive, setIsPanelBackgroundActive] = useState(false);
    const [isLoading, setIsLoading] = useState(true);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [previewErrored, setPreviewErrored] = useState(false);
    const { addFlash, addError, clearFlashes } = useStoreActions((actions: Actions<ApplicationStore>) => actions.flashes);

    const refreshPanelBackgroundPreference = () => {
        const preference = getPanelBackgroundPreference(userUuid, serverRouteIdentifier);
        const nextImageUrl = preference.imageUrl.trim();

        setPanelBackgroundImageUrl(nextImageUrl);
        setIsPanelBackgroundActive(preference.enabled && nextImageUrl !== '');
    };

    useEffect(() => {
        let isMounted = true;

        refreshPanelBackgroundPreference();
        setIsLoading(true);
        getServerBackgroundPreference(serverUuid)
            .then((preference) => {
                if (!isMounted) {
                    return;
                }

                const nextImageUrl = preference.imageUrl ?? '';
                setImageUrl(nextImageUrl);
                setSavedImageUrl(nextImageUrl);
                setPreviewErrored(false);
            })
            .catch((error) => {
                if (!isMounted) {
                    return;
                }

                addError({ key: 'settings', message: httpErrorToHuman(error) });
            })
            .then(() => {
                if (isMounted) {
                    setIsLoading(false);
                }
            });

        return () => {
            isMounted = false;
        };
    }, [serverUuid, serverRouteIdentifier, userUuid]);

    const normalizedImageUrl = imageUrl.trim();
    const normalizedSavedImageUrl = savedImageUrl.trim();
    const hasChanges = normalizedImageUrl !== normalizedSavedImageUrl;
    const hasCustomBackground = normalizedSavedImageUrl !== '';
    const isUrlTooLong = normalizedImageUrl.length > MAX_URL_LENGTH;
    const canSave = !isLoading && !isSubmitting && hasChanges && !isUrlTooLong;
    const canRemove = !isLoading && !isSubmitting && (hasCustomBackground || normalizedImageUrl !== '');
    const canUpload = !isLoading && !isSubmitting && !isNullOrOversized(selectedFile);
    const canApplyPanelBackground = !isLoading && !isSubmitting && normalizedImageUrl !== '';
    const canClearPanelBackground = !isLoading && !isSubmitting && isPanelBackgroundActive;

    const helperText = useMemo(() => {
        if (isUrlTooLong) {
            return `URL is too long. Maximum length is ${MAX_URL_LENGTH} characters.`;
        }

        return 'Use a direct media URL (PNG/JPG/WEBP/GIF/MP4/WEBM) or upload your own file below.';
    }, [isUrlTooLong]);

    const showVideoPreview = normalizedImageUrl !== '' && isVideoBackgroundUrl(normalizedImageUrl);
    const isPanelBackgroundVideo = isVideoBackgroundUrl(panelBackgroundImageUrl);

    const applyAsPanelBackground = (nextImageUrl: string) => {
        const normalizedNextImageUrl = nextImageUrl.trim();
        if (normalizedNextImageUrl === '') {
            clearPanelBackgroundPreference(userUuid, serverRouteIdentifier);
            refreshPanelBackgroundPreference();

            return;
        }

        setPanelBackgroundPreference(userUuid, serverRouteIdentifier, normalizedNextImageUrl, true);
        refreshPanelBackgroundPreference();
    };

    const persist = async (nextImageUrl: string) => {
        clearFlashes('settings');
        setIsSubmitting(true);

        try {
            const response = await setServerBackgroundPreference(serverUuid, nextImageUrl);
            const persistedImageUrl = (response.imageUrl || '').trim();

            setImageUrl(persistedImageUrl);
            setSavedImageUrl(persistedImageUrl);
            setPreviewErrored(false);
            window.dispatchEvent(new Event('serverbackgrounds:invalidate'));
            if (syncPanelBackgroundOnSave) {
                applyAsPanelBackground(persistedImageUrl);
            }

            addFlash({
                key: 'settings',
                type: 'success',
                message:
                    persistedImageUrl === ''
                        ? 'Your custom server background has been removed.'
                        : syncPanelBackgroundOnSave
                        ? 'Your custom server background and this server panel background have been updated.'
                        : 'Your custom server background has been updated.',
            });
        } catch (error) {
            addError({ key: 'settings', message: httpErrorToHuman(error) });
        } finally {
            setIsSubmitting(false);
        }
    };

    const uploadSelectedFile = async () => {
        if (selectedFile === null) {
            return;
        }

        if (selectedFile.size > MAX_UPLOAD_SIZE_BYTES) {
            addError({
                key: 'settings',
                message: 'Selected file is too large. Maximum allowed upload size is 50MB.',
            });

            return;
        }

        clearFlashes('settings');
        setIsSubmitting(true);

        try {
            const response = await uploadServerBackgroundPreference(serverUuid, selectedFile);
            const persistedImageUrl = (response.imageUrl || '').trim();

            setImageUrl(persistedImageUrl);
            setSavedImageUrl(persistedImageUrl);
            setPreviewErrored(false);
            setSelectedFile(null);
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
            window.dispatchEvent(new Event('serverbackgrounds:invalidate'));
            if (syncPanelBackgroundOnSave) {
                applyAsPanelBackground(persistedImageUrl);
            }

            addFlash({
                key: 'settings',
                type: 'success',
                message: syncPanelBackgroundOnSave
                    ? 'Your uploaded server and this server panel background has been applied.'
                    : 'Your uploaded server background has been applied.',
            });
        } catch (error) {
            addError({ key: 'settings', message: httpErrorToHuman(error) });
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <TitledGreyBox title={'Server Background'} css={tw`relative mb-6 md:mb-10`}>
            <SpinnerOverlay visible={isLoading || isSubmitting} />
            <div>
                <Label htmlFor={'serverBackgroundUrl'}>Background URL</Label>
                <Input
                    id={'serverBackgroundUrl'}
                    type={'text'}
                    value={imageUrl}
                    placeholder={'https://example.com/background.gif or background.mp4'}
                    onChange={(event) => setImageUrl(event.currentTarget.value)}
                    maxLength={MAX_URL_LENGTH}
                />
                <p css={tw`text-xs text-neutral-400 mt-2`}>{helperText}</p>
            </div>

            <div css={tw`mt-4`}>
                <Label htmlFor={'serverBackgroundUpload'}>Upload Media</Label>
                <input
                    ref={fileInputRef}
                    id={'serverBackgroundUpload'}
                    type={'file'}
                    accept={'image/png,image/jpeg,image/webp,image/gif,video/mp4,video/webm'}
                    css={tw`mt-2 block w-full text-sm text-neutral-200`}
                    onChange={(event) => setSelectedFile(event.currentTarget.files?.[0] ?? null)}
                />
                <p css={tw`text-xs text-neutral-400 mt-2`}>
                    Max file size: 50MB. Supported: PNG/JPG/WEBP/GIF/MP4/WEBM.
                </p>
            </div>

            <div css={tw`mt-4`}>
                <label htmlFor={'syncPanelBackgroundOnSave'} css={tw`flex items-center gap-2 text-sm text-neutral-200`}>
                    <input
                        id={'syncPanelBackgroundOnSave'}
                        type={'checkbox'}
                        checked={syncPanelBackgroundOnSave}
                        onChange={(event) => setSyncPanelBackgroundOnSave(event.currentTarget.checked)}
                    />
                    Also apply this media as my panel background for this server only when saving/uploading.
                </label>
            </div>

            {normalizedImageUrl !== '' && !previewErrored && (
                <div css={tw`mt-4`}>
                    <Label>Preview</Label>
                    {showVideoPreview ? (
                        <video
                            src={normalizedImageUrl}
                            autoPlay
                            muted
                            loop
                            playsInline
                            css={tw`mt-2 w-full max-h-36 rounded object-cover`}
                            onError={() => setPreviewErrored(true)}
                        />
                    ) : (
                        <img
                            src={normalizedImageUrl}
                            alt={'Server background preview'}
                            css={tw`mt-2 w-full max-h-36 rounded object-cover`}
                            onError={() => setPreviewErrored(true)}
                        />
                    )}
                </div>
            )}

            <div css={tw`mt-6`}>
                <Label>Panel Background (User)</Label>
                <p css={tw`text-xs text-neutral-400 mt-2`}>
                    {isPanelBackgroundActive
                        ? 'You are currently using a custom panel background on this server only.'
                        : 'You are currently using the panel default background for this server.'}
                </p>
                {isPanelBackgroundActive && panelBackgroundImageUrl !== '' && (
                    <div css={tw`mt-3`}>
                        {isPanelBackgroundVideo ? (
                            <video
                                src={panelBackgroundImageUrl}
                                autoPlay
                                muted
                                loop
                                playsInline
                                css={tw`w-full max-h-28 rounded object-cover`}
                            />
                        ) : (
                            <img
                                src={panelBackgroundImageUrl}
                                alt={'Current panel background preview'}
                                css={tw`w-full max-h-28 rounded object-cover`}
                            />
                        )}
                    </div>
                )}
                <div css={tw`mt-4 flex flex-wrap gap-3`}>
                    <Button.Text
                        type={'button'}
                        onClick={() => {
                            applyAsPanelBackground(normalizedImageUrl);
                            addFlash({
                                key: 'settings',
                                type: 'success',
                                message: 'Panel background updated for this server.',
                            });
                        }}
                        disabled={!canApplyPanelBackground}
                    >
                        Use Current As This Server Panel Background
                    </Button.Text>
                    <Button
                        type={'button'}
                        variant={Button.Variants.Secondary}
                        onClick={() => {
                            clearPanelBackgroundPreference(userUuid, serverRouteIdentifier);
                            refreshPanelBackgroundPreference();
                            addFlash({
                                key: 'settings',
                                type: 'success',
                                message: 'Panel background reset to default for this server.',
                            });
                        }}
                        disabled={!canClearPanelBackground}
                    >
                        Reset This Server Panel Background
                    </Button>
                </div>
            </div>

            <div css={tw`mt-6 flex justify-end gap-3`}>
                <Button
                    type={'button'}
                    onClick={uploadSelectedFile}
                    disabled={!canUpload}
                >
                    Upload File
                </Button>
                <Button
                    type={'button'}
                    onClick={() => persist(normalizedImageUrl)}
                    disabled={!canSave}
                >
                    Save Background
                </Button>
                <Button.Danger
                    type={'button'}
                    variant={Button.Variants.Secondary}
                    onClick={() => persist('')}
                    disabled={!canRemove}
                >
                    Remove
                </Button.Danger>
            </div>
        </TitledGreyBox>
    );
};

const isNullOrOversized = (file: File | null): boolean => {
    if (file === null) {
        return true;
    }

    return file.size > MAX_UPLOAD_SIZE_BYTES;
};
