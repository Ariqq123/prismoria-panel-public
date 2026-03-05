import React, { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { useStoreState } from 'easy-peasy';
import { boolean, object, string } from 'yup';
import { Field, Form, Formik, FormikHelpers } from 'formik';
import { PlusIcon } from '@heroicons/react/solid';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faPlug } from '@fortawesome/free-solid-svg-icons';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import Modal from '@/components/elements/Modal';
import Input from '@/components/elements/Input';
import { Button } from '@/components/elements/button';
import FlashMessageRender from '@/components/FlashMessageRender';
import FormikFieldWrapper from '@/components/elements/FormikFieldWrapper';
import { useFlashKey } from '@/plugins/useFlash';
import { createExternalPanelConnection } from '@/api/account/externalPanels';

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

interface ExternalPanelQuickAddButtonProps {
    hideTrigger?: boolean;
}

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

export default ({ hideTrigger = false }: ExternalPanelQuickAddButtonProps) => {
    const [modalVisible, setModalVisible] = useState(false);
    const { clearAndAddHttpError, clearFlashes } = useFlashKey('external-panels-quick-add');
    const isAuthenticated = useStoreState((state) => !!state.user.data?.uuid);
    const location = useLocation();
    const isFileEditorRoute = /^\/server\/[^/]+\/files\/(edit|new)(?:\/|$)/.test(location.pathname);

    useEffect(() => {
        const handleExternalApiOpen = () => setModalVisible(true);
        window.addEventListener('external-api:open', handleExternalApiOpen);

        return () => {
            window.removeEventListener('external-api:open', handleExternalApiOpen);
        };
    }, []);

    if (!isAuthenticated || location.pathname.startsWith('/auth') || isFileEditorRoute) {
        return null;
    }

    const closeModal = () => {
        clearFlashes();
        setModalVisible(false);
    };

    const submit = (values: Values, { setSubmitting, resetForm }: FormikHelpers<Values>) => {
        clearFlashes();

        createExternalPanelConnection({
            name: values.name,
            panelUrl: values.panelUrl,
            websocketOrigin: values.websocketOrigin || undefined,
            apiKey: values.apiKey,
            defaultConnection: values.defaultConnection,
        })
            .then(() => {
                resetForm({ values: defaultValues });
                setModalVisible(false);
            })
            .catch((error) => clearAndAddHttpError(error))
            .then(() => setSubmitting(false));
    };

    return (
        <>
            {!hideTrigger && (
                <Button
                    type={'button'}
                    size={Button.Sizes.Small}
                    onClick={() => setModalVisible(true)}
                    className={
                        'fixed bottom-6 right-6 z-50 flex items-center gap-2 rounded-full border border-neutral-500/70 bg-neutral-900/80 px-4 py-2 text-neutral-100 shadow-2xl backdrop-blur-md transition-all duration-150 hover:border-red-400/80 hover:text-red-100'
                    }
                >
                    <PlusIcon className={'w-4 h-4'} />
                    <span>Add External API</span>
                </Button>
            )}

            <Formik
                initialValues={defaultValues}
                validationSchema={object().shape({
                    name: string().nullable().max(191),
                    panelUrl: string().required('Panel URL is required.').url('Panel URL must be valid.'),
                    websocketOrigin: string()
                        .nullable()
                        .transform((value) => (value === '' ? null : value))
                        .url('Allowed Origin must be a valid URL.')
                        .max(191),
                    apiKey: string().required('A client API key is required.').min(16).max(512),
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
                            resetForm({ values: defaultValues });
                            closeModal();
                        }}
                    >
                        <DockModalSurface>
                            <FlashMessageRender byKey={'external-panels-quick-add'} className={'mb-6'} />
                            <div className={'mb-6 flex items-start gap-3'}>
                                <span
                                    className={
                                        'inline-flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border border-neutral-500/70 bg-neutral-800/80 text-red-200 shadow-lg'
                                    }
                                >
                                    <FontAwesomeIcon icon={faPlug} />
                                </span>
                                <div>
                                    <DockModalTitle>Add External Panel Connection</DockModalTitle>
                                    <DockModalDescription>
                                        Connect another panel using a client API key. This form matches your dock visual style.
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
                                    description={'The key is encrypted at rest and never returned to the frontend.'}
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
                                    <div className={'flex items-center rounded-xl border border-neutral-500/60 bg-neutral-800/70 px-3 py-2'}>
                                        <Field as={Input} type={'checkbox'} name={'defaultConnection'} checked={values.defaultConnection} />
                                        <span className={'ml-2 text-sm text-neutral-300'}>Set as default</span>
                                    </div>
                                </FormikFieldWrapper>
                                <div className={'mt-6 flex flex-wrap justify-end gap-2'}>
                                    <Button
                                        type={'button'}
                                        variant={Button.Variants.Secondary}
                                        className={
                                            'w-full rounded-full border border-neutral-500/70 bg-neutral-800/70 text-neutral-100 sm:w-auto'
                                        }
                                        onClick={closeModal}
                                    >
                                        Cancel
                                    </Button>
                                    <Button
                                        className={
                                            'w-full rounded-full border border-red-400/70 bg-neutral-800/80 text-red-100 shadow-lg sm:w-auto'
                                        }
                                        type={'submit'}
                                    >
                                        Add Connection
                                    </Button>
                                </div>
                            </Form>
                        </DockModalSurface>
                    </Modal>
                )}
            </Formik>
        </>
    );
};
