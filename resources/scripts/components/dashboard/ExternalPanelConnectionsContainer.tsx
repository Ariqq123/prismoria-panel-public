import React, { ChangeEvent, useEffect, useMemo, useRef, useState } from 'react';
import ContentBox from '@/components/elements/ContentBox';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import GreyRowBox from '@/components/elements/GreyRowBox';
import tw from 'twin.macro';
import Button from '@/components/elements/Button';
import { Button as HeaderButton } from '@/components/elements/button';
import { Field, Form, Formik, FormikHelpers } from 'formik';
import { boolean, object, string } from 'yup';
import Input from '@/components/elements/Input';
import FormikFieldWrapper from '@/components/elements/FormikFieldWrapper';
import { Actions, useStoreActions } from 'easy-peasy';
import { ApplicationStore } from '@/state';
import {
    createExternalPanelConnection,
    deleteExternalPanelConnection,
    ExternalPanelConnection,
    ExternalPanelConnectionImportResult,
    exportExternalPanelConnections,
    exportExternalPanelConnectionsPayload,
    getExternalPanelConnections,
    importExternalPanelConnections,
    updateExternalPanelConnection,
    verifyExternalPanelConnection,
} from '@/api/account/externalPanels';
import { useFlashKey } from '@/plugins/useFlash';
import { format } from 'date-fns';
import Modal from '@/components/elements/Modal';
import FlashMessageRender from '@/components/FlashMessageRender';
import { PlusIcon } from '@heroicons/react/solid';
import copy from 'copy-to-clipboard';
import styled from 'styled-components/macro';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faPlug, faStar } from '@fortawesome/free-solid-svg-icons';
import AnimatedGradientText from '@/components/elements/AnimatedGradientText';

interface Values {
    name: string;
    panelUrl: string;
    websocketOrigin: string;
    apiKey: string;
    defaultConnection: boolean;
}

const defaultValues: Values = {
    name: '',
    panelUrl: '',
    websocketOrigin: '',
    apiKey: '',
    defaultConnection: false,
};

const DockStyledContentBox = styled(ContentBox)`
    & > div:last-of-type {
        background: rgba(10, 14, 25, 0.76);
        border: 1px solid rgba(82, 82, 91, 0.72);
        border-radius: 1rem;
        backdrop-filter: blur(12px);
        box-shadow: 0 26px 50px rgba(0, 0, 0, 0.34);
    }
`;

const DockHeaderActions = styled.div`
    ${tw`flex flex-wrap items-center gap-2`};
`;

const DockHeaderButton = styled(HeaderButton)`
    ${tw`rounded-full border border-neutral-500/70 bg-neutral-800/80 px-4 text-neutral-100 shadow-md`};
    backdrop-filter: blur(8px);
    transition: border-color 170ms ease, color 170ms ease, transform 170ms ease, box-shadow 170ms ease;

    &:hover:not(:disabled) {
        border-color: rgba(248, 113, 113, 0.85);
        color: rgb(254 202 202);
        transform: translateY(-1px);
        box-shadow: 0 14px 28px rgba(0, 0, 0, 0.28);
    }
`;

const ConnectionCard = styled(GreyRowBox)<{ $connected: boolean }>`
    ${tw`items-start sm:items-center rounded-2xl border bg-neutral-900/75 p-4 gap-3 transition-all duration-150`};
    border-color: ${({ $connected }) => ($connected ? 'rgba(248, 113, 113, 0.7)' : 'rgba(82, 82, 91, 0.72)')};
    box-shadow: ${({ $connected }) =>
        $connected ? '0 14px 34px rgba(127, 29, 29, 0.24)' : '0 12px 28px rgba(0, 0, 0, 0.22)'};
    backdrop-filter: blur(10px);

    &:hover {
        border-color: rgba(248, 113, 113, 0.85);
    }
`;

const ConnectionStatusPill = styled.p<{ $connected: boolean }>`
    ${tw`inline-flex items-center rounded-full border px-2.5 py-1 text-2xs uppercase tracking-wide`};
    border-color: ${({ $connected }) => ($connected ? 'rgba(248, 113, 113, 0.65)' : 'rgba(113, 113, 122, 0.7)')};
    color: ${({ $connected }) => ($connected ? 'rgb(254 202 202)' : 'rgb(203 213 225)')};
    background: ${({ $connected }) => ($connected ? 'rgba(127, 29, 29, 0.28)' : 'rgba(39, 39, 42, 0.55)')};
`;

const ConnectionActionGroup = styled.div`
    ${tw`flex w-full sm:w-auto items-center justify-end flex-wrap gap-2 ml-0 sm:ml-4`};
`;

const DockModalSurface = styled.div`
    ${tw`rounded-2xl border border-neutral-500/70 bg-neutral-900/75 p-4 sm:p-5 shadow-2xl`};
    backdrop-filter: blur(12px);
`;

const DockModalTitle = styled.h2`
    ${tw`text-2xl mb-1 text-neutral-100`};
`;

const DockModalDescription = styled.p`
    ${tw`text-sm text-neutral-300`};
`;

export default () => {
    const fileInputRef = useRef<HTMLInputElement | null>(null);
    const [connections, setConnections] = useState<ExternalPanelConnection[]>([]);
    const [loading, setLoading] = useState(true);
    const [isExporting, setIsExporting] = useState(false);
    const [isCopying, setIsCopying] = useState(false);
    const [isImporting, setIsImporting] = useState(false);
    const [importSummary, setImportSummary] = useState<ExternalPanelConnectionImportResult | null>(null);
    const [modalVisible, setModalVisible] = useState(false);
    const [editingConnection, setEditingConnection] = useState<ExternalPanelConnection | null>(null);
    const { addFlash } = useStoreActions((actions: Actions<ApplicationStore>) => actions.flashes);
    const { addError, clearAndAddHttpError, clearFlashes } = useFlashKey('external-panels');

    const formValues = useMemo<Values>(() => {
        if (!editingConnection) {
            return defaultValues;
        }

        return {
            name: editingConnection.name || '',
            panelUrl: editingConnection.panelUrl,
            websocketOrigin: editingConnection.websocketOrigin || '',
            apiKey: '',
            defaultConnection: editingConnection.defaultConnection,
        };
    }, [editingConnection]);

    const loadConnections = () => {
        setLoading(true);

        getExternalPanelConnections()
            .then((data) => setConnections(data))
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setLoading(false));
    };

    useEffect(() => {
        loadConnections();
    }, []);

    const closeModal = () => {
        setEditingConnection(null);
        setModalVisible(false);
    };

    const submit = (values: Values, { setSubmitting, resetForm }: FormikHelpers<Values>) => {
        clearFlashes();

        const action = editingConnection
            ? updateExternalPanelConnection(editingConnection.id, {
                  name: values.name,
                  panelUrl: values.panelUrl,
                  websocketOrigin: values.websocketOrigin || undefined,
                  apiKey: values.apiKey.length > 0 ? values.apiKey : undefined,
                  defaultConnection: values.defaultConnection,
              })
            : createExternalPanelConnection({
                  name: values.name,
                  panelUrl: values.panelUrl,
                  websocketOrigin: values.websocketOrigin || undefined,
                  apiKey: values.apiKey,
                  defaultConnection: values.defaultConnection,
              });

        action
            .then(() => {
                closeModal();
                resetForm({ values: defaultValues });
                loadConnections();
            })
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setSubmitting(false));
    };

    const onEdit = (connection: ExternalPanelConnection) => {
        setEditingConnection(connection);
        setModalVisible(true);
    };

    const onVerify = (connection: ExternalPanelConnection) => {
        clearFlashes();

        verifyExternalPanelConnection(connection.id)
            .then(() => loadConnections())
            .catch((error) => clearAndAddHttpError(error));
    };

    const onDelete = (connection: ExternalPanelConnection) => {
        clearFlashes();

        deleteExternalPanelConnection(connection.id)
            .then(() => {
                if (editingConnection?.id === connection.id) {
                    closeModal();
                }

                loadConnections();
            })
            .catch((error) => clearAndAddHttpError(error));
    };

    const onExport = () => {
        clearFlashes();
        setIsExporting(true);

        exportExternalPanelConnections()
            .then(({ blob, filename }) => {
                const url = window.URL.createObjectURL(blob);
                const anchor = document.createElement('a');
                anchor.href = url;
                anchor.download = filename;
                document.body.appendChild(anchor);
                anchor.click();
                anchor.remove();
                window.URL.revokeObjectURL(url);
            })
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setIsExporting(false));
    };

    const onImport = (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.currentTarget.files?.[0];
        event.currentTarget.value = '';

        if (!file) {
            return;
        }

        clearFlashes();
        setImportSummary(null);
        setIsImporting(true);

        importExternalPanelConnections(file)
            .then((summary) => {
                setImportSummary(summary);
                if (summary.errors.length > 0) {
                    addError(`Imported with warnings: ${summary.errors[0]}`);
                }

                loadConnections();
            })
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setIsImporting(false));
    };

    const onCopyJson = () => {
        clearFlashes();
        setIsCopying(true);

        exportExternalPanelConnectionsPayload()
            .then((payload) => {
                const copied = copy(JSON.stringify(payload, null, 2));
                if (!copied) {
                    throw new Error('Failed to copy external API export JSON to clipboard.');
                }

                addFlash({
                    key: 'external-panels',
                    type: 'success',
                    message: 'External API export JSON copied to clipboard.',
                });
            })
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setIsCopying(false));
    };

    return (
        <div css={tw`mt-8`}>
            <DockStyledContentBox
                title={
                    <AnimatedGradientText speed={1.15} colorFrom={'#fecaca'} colorTo={'#ef4444'}>
                        External Panel Connections
                    </AnimatedGradientText>
                }
                headerAction={
                    <DockHeaderActions>
                        <DockHeaderButton
                            type={'button'}
                            size={HeaderButton.Sizes.Small}
                            onClick={onExport}
                            disabled={loading || isExporting || isCopying || isImporting}
                        >
                            {isExporting ? 'Exporting...' : 'Export'}
                        </DockHeaderButton>
                        <DockHeaderButton
                            type={'button'}
                            size={HeaderButton.Sizes.Small}
                            onClick={onCopyJson}
                            disabled={loading || isExporting || isCopying || isImporting}
                        >
                            {isCopying ? 'Copying...' : 'Copy JSON'}
                        </DockHeaderButton>
                        <DockHeaderButton
                            type={'button'}
                            size={HeaderButton.Sizes.Small}
                            onClick={() => fileInputRef.current?.click()}
                            disabled={loading || isExporting || isCopying || isImporting}
                        >
                            {isImporting ? 'Importing...' : 'Import'}
                        </DockHeaderButton>
                        <DockHeaderButton
                            type={'button'}
                            size={HeaderButton.Sizes.Small}
                            onClick={() => {
                                setEditingConnection(null);
                                setModalVisible(true);
                            }}
                            className={'flex items-center gap-2 border-red-400/70 text-red-100'}
                        >
                            <PlusIcon className={'w-4 h-4'} />
                            Add Connection
                        </DockHeaderButton>
                    </DockHeaderActions>
                }
                showFlashes={'external-panels'}
            >
                <SpinnerOverlay visible={loading || isImporting} />
                <input
                    ref={fileInputRef}
                    type={'file'}
                    accept={'.json,application/json'}
                    onChange={onImport}
                    hidden
                />

                {importSummary && (
                    <p css={tw`mb-4 rounded-xl border border-neutral-500/60 bg-neutral-800/70 px-3 py-2 text-xs text-neutral-200`}>
                        Imported {importSummary.imported}, updated {importSummary.updated}, skipped {importSummary.skipped} of{' '}
                        {importSummary.total} connection(s).
                    </p>
                )}

                {connections.length === 0 && !loading ? (
                    <p css={tw`rounded-xl border border-neutral-500/60 bg-neutral-800/70 px-4 py-3 text-sm text-neutral-300`}>
                        No external panel connections configured.
                    </p>
                ) : (
                    <div css={tw`space-y-3`}>
                        {connections.map((connection) => (
                            <ConnectionCard key={connection.id} $connected={connection.status === 'connected'}>
                                <span
                                    css={tw`inline-flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border border-neutral-500/70 bg-neutral-800/80 text-red-200 shadow-lg`}
                                >
                                    <FontAwesomeIcon icon={faPlug} />
                                </span>
                                <div css={tw`flex-1 overflow-hidden`}>
                                    <p css={tw`text-sm text-neutral-100 break-words`}>
                                        {connection.name || 'External Panel'}
                                        {connection.defaultConnection && (
                                            <span
                                                css={tw`ml-2 inline-flex items-center rounded-full border border-red-400/60 bg-red-900/30 px-2 py-0.5 text-2xs uppercase tracking-wide text-red-100`}
                                            >
                                                <FontAwesomeIcon icon={faStar} css={tw`mr-1 text-2xs`} />
                                                Default
                                            </span>
                                        )}
                                    </p>
                                    <p css={tw`text-xs text-neutral-300 break-words`}>{connection.panelUrl}</p>
                                    {connection.websocketOrigin && (
                                        <p css={tw`text-xs text-neutral-400 break-words mt-1`}>
                                            WS Origin: {connection.websocketOrigin}
                                        </p>
                                    )}
                                    <div css={tw`mt-2 flex flex-wrap items-center gap-2`}>
                                        <ConnectionStatusPill $connected={connection.status === 'connected'}>
                                            {connection.status === 'connected' ? 'Connected' : 'Disconnected'}
                                        </ConnectionStatusPill>
                                        <p css={tw`text-2xs text-neutral-400`}>
                                            {connection.lastVerifiedAt
                                                ? `Verified ${format(connection.lastVerifiedAt, 'MMM do, yyyy HH:mm')}`
                                                : 'Not verified yet'}
                                        </p>
                                    </div>
                                </div>
                                <ConnectionActionGroup>
                                    <Button
                                        size={'xsmall'}
                                        isSecondary
                                        className={'rounded-full border-neutral-500/70 bg-neutral-800/70'}
                                        onClick={() => onVerify(connection)}
                                    >
                                        Verify
                                    </Button>
                                    <Button
                                        size={'xsmall'}
                                        isSecondary
                                        className={'rounded-full border-neutral-500/70 bg-neutral-800/70'}
                                        onClick={() => onEdit(connection)}
                                    >
                                        Edit
                                    </Button>
                                    <Button
                                        size={'xsmall'}
                                        color={'red'}
                                        isSecondary
                                        className={'rounded-full border-red-400/70 bg-red-900/25 text-red-100'}
                                        onClick={() => onDelete(connection)}
                                    >
                                        Remove
                                    </Button>
                                </ConnectionActionGroup>
                            </ConnectionCard>
                        ))}
                    </div>
                )}
            </DockStyledContentBox>

            <Formik
                enableReinitialize
                initialValues={formValues}
                validationSchema={object().shape({
                    name: string().nullable().max(191),
                    panelUrl: string().required('Panel URL is required.').url('Panel URL must be valid.'),
                    websocketOrigin: string()
                        .nullable()
                        .transform((value) => (value === '' ? null : value))
                        .url('Allowed Origin must be a valid URL.')
                        .max(191),
                    apiKey: editingConnection
                        ? string().max(512)
                        : string().required('A client API key is required.').min(16),
                    defaultConnection: boolean(),
                })}
                onSubmit={submit}
            >
                {({ isSubmitting, values, resetForm }) => (
                    <Modal
                        visible={modalVisible}
                        dismissable={!isSubmitting}
                        showSpinnerOverlay={isSubmitting}
                        onDismissed={() => {
                            resetForm();
                            closeModal();
                        }}
                    >
                        <DockModalSurface>
                            <FlashMessageRender byKey={'external-panels'} css={tw`mb-6`} />
                            <div css={tw`mb-6 flex items-start gap-3`}>
                                <span
                                    css={tw`inline-flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border border-neutral-500/70 bg-neutral-800/80 text-red-200 shadow-lg`}
                                >
                                    <FontAwesomeIcon icon={faPlug} />
                                </span>
                                <div>
                                    <DockModalTitle>
                                        {editingConnection ? 'Edit External Panel Connection' : 'Add External Panel Connection'}
                                    </DockModalTitle>
                                    <DockModalDescription>
                                        Manage remote panel credentials and connection defaults in one place.
                                    </DockModalDescription>
                                </div>
                            </div>
                            <Form>
                                <FormikFieldWrapper
                                    name={'name'}
                                    label={'Name / Label'}
                                    description={'Optional label shown on the dashboard.'}
                                    className={'mb-4'}
                                >
                                    <Field name={'name'} as={Input} />
                                </FormikFieldWrapper>
                                <FormikFieldWrapper
                                    name={'panelUrl'}
                                    label={'External Panel URL'}
                                    description={'The base URL to the external panel, for example https://panel.example.com'}
                                    className={'mb-4'}
                                >
                                    <Field name={'panelUrl'} as={Input} />
                                </FormikFieldWrapper>
                                <FormikFieldWrapper
                                    name={'apiKey'}
                                    label={'Client API Key'}
                                    description={
                                        editingConnection
                                            ? 'Leave blank to keep the current key. A new key will be validated before saving.'
                                            : 'The key is encrypted at rest and never returned to the frontend.'
                                    }
                                    className={'mb-4'}
                                >
                                    <Field name={'apiKey'} as={Input} type={'password'} autoComplete={'new-password'} />
                                </FormikFieldWrapper>
                                <FormikFieldWrapper
                                    name={'websocketOrigin'}
                                    label={'Allowed Origin'}
                                    description={
                                        'Optional. Override websocket Origin header for this panel (for hosts with strict origin policy).'
                                    }
                                    className={'mb-4'}
                                >
                                    <Field name={'websocketOrigin'} as={Input} placeholder={'https://base-panel.domain'} />
                                </FormikFieldWrapper>
                                <FormikFieldWrapper
                                    name={'defaultConnection'}
                                    label={'Default Connection'}
                                    description={'Use this connection by default when selecting external servers.'}
                                    className={'mb-4'}
                                >
                                    <div css={tw`flex items-center rounded-xl border border-neutral-500/60 bg-neutral-800/70 px-3 py-2`}>
                                        <Field
                                            as={Input}
                                            type={'checkbox'}
                                            name={'defaultConnection'}
                                            checked={values.defaultConnection}
                                        />
                                        <span css={tw`ml-2 text-sm text-neutral-300`}>Set as default</span>
                                    </div>
                                </FormikFieldWrapper>
                                <div css={tw`mt-6 flex flex-wrap justify-end gap-2`}>
                                    <Button
                                        type={'button'}
                                        isSecondary
                                        css={tw`w-full sm:w-auto rounded-full border-neutral-500/70 bg-neutral-800/70`}
                                        onClick={closeModal}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        css={tw`w-full sm:w-auto rounded-full border border-red-400/70 bg-neutral-800/80 text-red-100 shadow-lg`}
                                        type={'submit'}
                                    >
                                        {editingConnection ? 'Save Connection' : 'Add Connection'}
                                    </Button>
                                </div>
                            </Form>
                        </DockModalSurface>
                    </Modal>
                )}
            </Formik>
        </div>
    );
};
