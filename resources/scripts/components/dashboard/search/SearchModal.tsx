import React, { useEffect, useRef, useState } from 'react';
import Modal, { RequiredModalProps } from '@/components/elements/Modal';
import { Field, Form, Formik, FormikHelpers, useFormikContext } from 'formik';
import { Actions, useStoreActions, useStoreState } from 'easy-peasy';
import { object, string } from 'yup';
import debounce from 'debounce';
import FormikFieldWrapper from '@/components/elements/FormikFieldWrapper';
import InputSpinner from '@/components/elements/InputSpinner';
import getServers from '@/api/getServers';
import { Server } from '@/api/server/getServer';
import { ApplicationStore } from '@/state';
import { Link } from 'react-router-dom';
import styled from 'styled-components/macro';
import tw from 'twin.macro';
import Input from '@/components/elements/Input';
import { ip } from '@/lib/formatters';
import { usePersistedState } from '@/plugins/usePersistedState';

type Props = RequiredModalProps;

interface Values {
    term: string;
}

const searchTextCache = new WeakMap<Server, string>();

const normalizeUrlLikeValue = (value: string): string => value.replace(/^https?:\/\//, '').replace(/\/+$/, '');

const buildSearchText = (server: Server): string => {
    const cached = searchTextCache.get(server);
    if (cached) {
        return cached;
    }

    const defaultAllocations = server.allocations.reduce<string>((result, allocation) => {
        if (!allocation.isDefault) {
            return result;
        }

        const next = `${allocation.alias || allocation.ip}:${allocation.port}`;

        return result ? `${result} ${next}` : next;
    }, '');

    const externalPanelUrl = (server.externalPanelUrl || '').toLowerCase();
    const normalizedExternalPanelUrl = normalizeUrlLikeValue(externalPanelUrl);
    const haystack = [
        server.name,
        server.id,
        server.uuid,
        server.description || '',
        server.node || '',
        defaultAllocations,
        server.externalPanelName || '',
        externalPanelUrl,
        normalizedExternalPanelUrl,
        server.externalServerIdentifier || '',
    ]
        .join(' ')
        .toLowerCase();

    searchTextCache.set(server, haystack);

    return haystack;
};

const filterServerPool = (items: Server[], term: string): Server[] => {
    const normalizedTerm = term.trim().toLowerCase();
    if (normalizedTerm.length < 3) {
        return [];
    }

    const normalizedUrlTerm = normalizeUrlLikeValue(normalizedTerm);

    return items
        .filter((server) => {
            const haystack = buildSearchText(server);

            return (
                haystack.includes(normalizedTerm) ||
                (normalizedUrlTerm.length > 0 && haystack.includes(normalizedUrlTerm))
            );
        })
        .slice(0, 5);
};

const ServerResult = styled(Link)`
    ${tw`flex items-center bg-neutral-900 p-4 rounded border-l-4 border-neutral-900 no-underline transition-all duration-150`};

    &:hover {
        ${tw`shadow border-cyan-500`};
    }

    &:not(:last-of-type) {
        ${tw`mb-2`};
    }
`;

const SearchWatcher = () => {
    const { values, submitForm } = useFormikContext<Values>();
    const userUuid = useStoreState((state) => state.user.data?.uuid || '');
    const [, setDashboardSearch] = usePersistedState<string>(`${userUuid}:dashboard_server_search`, '');

    useEffect(() => {
        setDashboardSearch(values.term);

        if (values.term.trim().length >= 3) {
            submitForm();
        }
    }, [values.term, submitForm, setDashboardSearch]);

    return null;
};

export default ({ ...props }: Props) => {
    const ref = useRef<HTMLInputElement>(null);
    const userUuid = useStoreState((state) => state.user.data?.uuid || '');
    const isAdmin = useStoreState((state) => state.user.data!.rootAdmin);
    const [dashboardSearch] = usePersistedState<string>(`${userUuid}:dashboard_server_search`, '');
    const initialSearchTerm = typeof dashboardSearch === 'string' ? dashboardSearch : '';
    const [servers, setServers] = useState<Server[]>([]);
    const [serverPool, setServerPool] = useState<Server[]>([]);
    const latestSearchRequestId = useRef(0);
    const { clearAndAddHttpError, clearFlashes } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes
    );

    const search = debounce(({ term }: Values, { setSubmitting }: FormikHelpers<Values>) => {
        clearFlashes('search');
        const normalizedTerm = term.trim();
        const requestId = ++latestSearchRequestId.current;
        if (normalizedTerm.length < 3) {
            setServers([]);
            setSubmitting(false);
            ref.current?.focus();

            return;
        }

        const localMatches = filterServerPool(serverPool, normalizedTerm);
        if (localMatches.length > 0) {
            setServers(localMatches);
        }

        getServers({
            query: normalizedTerm,
            type: isAdmin ? 'admin-all' : undefined,
            source: 'all',
            externalFetch: 'cached-only',
            perPage: 100,
        })
            .then((response) => {
                if (requestId !== latestSearchRequestId.current) {
                    return;
                }

                const items = response.items || [];
                setServerPool((current) => (current.length >= items.length ? current : items));
                const remoteMatches = items.filter((_, index) => index < 5);
                if (remoteMatches.length > 0 || localMatches.length < 1) {
                    setServers(remoteMatches);
                }
            })
            .catch((error) => {
                console.error(error);
                if (requestId === latestSearchRequestId.current) {
                    clearAndAddHttpError({ key: 'search', error });
                }
            })
            .then(() => {
                if (requestId === latestSearchRequestId.current) {
                    setSubmitting(false);
                    ref.current?.focus();
                }
            });
    }, 250);

    useEffect(() => {
        if (!props.visible) {
            return;
        }

        let active = true;
        getServers({
            type: isAdmin ? 'admin-all' : undefined,
            source: 'all',
            externalFetch: 'cached-only',
            perPage: 100,
        })
            .then((response) => {
                if (!active) {
                    return;
                }

                setServerPool(response.items || []);
            })
            .catch((error) => {
                if (active) {
                    clearAndAddHttpError({ key: 'search', error });
                }
            });

        return () => {
            active = false;
        };
    }, [props.visible, isAdmin, clearAndAddHttpError]);

    useEffect(() => {
        if (props.visible) {
            if (ref.current) ref.current.focus();
        }
    }, [props.visible]);

    // Formik does not support an innerRef on custom components.
    const InputWithRef = (props: any) => <Input autoFocus {...props} ref={ref} />;

    return (
        <Formik
            onSubmit={search}
            validationSchema={object().shape({
                term: string().min(3, 'Please enter at least three characters to begin searching.'),
            })}
            initialValues={{ term: initialSearchTerm } as Values}
            enableReinitialize
        >
            {({ isSubmitting }) => (
                <Modal {...props}>
                    <Form>
                        <FormikFieldWrapper
                            name={'term'}
                            label={'Search term'}
                            description={'Enter a server name, UUID, allocation, or external panel URL to begin searching.'}
                        >
                            <SearchWatcher />
                            <InputSpinner visible={isSubmitting}>
                                <Field as={InputWithRef} name={'term'} />
                            </InputSpinner>
                        </FormikFieldWrapper>
                    </Form>
                    {servers.length > 0 && (
                        <div css={tw`mt-6`}>
                            {servers.map((server) => (
                                <ServerResult
                                    key={server.uuid}
                                    to={`/server/${server.id}`}
                                    onClick={() => props.onDismissed()}
                                >
                                    <div css={tw`flex-1 mr-4`}>
                                        <p css={tw`text-sm`}>{server.name}</p>
                                        <p css={tw`mt-1 text-xs text-neutral-400`}>
                                            {server.allocations
                                                .filter((alloc) => alloc.isDefault)
                                                .map((allocation) => (
                                                    <span key={allocation.ip + allocation.port.toString()}>
                                                        {allocation.alias || ip(allocation.ip)}:{allocation.port}
                                                    </span>
                                                ))}
                                        </p>
                                    </div>
                                    <div css={tw`flex-none text-right`}>
                                        <span css={tw`text-xs py-1 px-2 bg-cyan-800 text-cyan-100 rounded`}>
                                            {server.node}
                                        </span>
                                    </div>
                                </ServerResult>
                            ))}
                        </div>
                    )}
                </Modal>
            )}
        </Formik>
    );
};
