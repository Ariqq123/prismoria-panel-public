import React, { useCallback, useEffect, useMemo, useState } from 'react';
import useSWR from 'swr';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import { ServerContext } from '@/state/server';
import useFlash from '@/plugins/useFlash';
import Spinner from '@/components/elements/Spinner';
import tw from 'twin.macro';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import { Field as FormikField, Form, Formik, FormikHelpers } from 'formik';
import Field from '@/components/elements/Field';
import Button from '@/components/elements/Button';
import { object, string } from 'yup';
import FormikFieldWrapper from '@/components/elements/FormikFieldWrapper';
import Select from '@/components/elements/Select';
import Label from '@/components/elements/Label';
import FlashMessageRender from '@/components/FlashMessageRender';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faNetworkWired } from '@fortawesome/free-solid-svg-icons';
import GreyRowBox from '@/components/elements/GreyRowBox';
import styled from 'styled-components/macro';
import MessageBox from '@/components/MessageBox';
import DeleteSubdomainButton from '@/blueprint/extensions/subdomainmanager/DeleteSubdomainButton';
import createServerSubdomain from '@/blueprint/extensions/subdomainmanager/api/createServerSubdomain';
import getServerSubdomains from '@/blueprint/extensions/subdomainmanager/api/getServerSubdomains';
import getMin3AvailableDomains, { Min3AvailableDomainItem } from '@/blueprint/extensions/subdomainmanager/api/getMin3AvailableDomains';

const Code = styled.code`${tw`font-mono py-1 px-2 bg-neutral-900 rounded text-sm inline-block break-all`}`;

interface SubdomainItem {
    id: number;
    subdomain: string;
    domain: string;
    port: number;
    record_type: string;
    srv_service?: string | null;
    srv_protocol_type?: string | null;
    srv_priority?: number | null;
    srv_weight?: number | null;
    srv_port?: number | null;
}

interface DomainItem {
    id: number;
    domain: string;
    provider?: 'cloudflare' | 'min3' | string;
}

export interface SubdomainResponse {
    subdomains: SubdomainItem[];
    domains: DomainItem[];
    ipAlias: string;
    isExternal?: boolean;
    usingFallbackDomains?: boolean;
}

interface CreateValues {
    subdomain: string;
    domainId: number;
    advancedSrv: boolean;
    srvService: string;
    srvProtocolType: 'tcp' | 'udp' | 'tls';
    srvPriority: number;
    srvWeight: number;
    srvPort: number;
}

const isValidMin3LookupSubdomain = (subdomain: string): boolean => (
    /^[a-z0-9](?:[a-z0-9-]{0,18}[a-z0-9])?$/.test(subdomain)
);

const mergeDomainOptions = (primary: DomainItem[], secondary: DomainItem[]): DomainItem[] => {
    const merged = new Map<string, DomainItem>();

    primary.forEach((item) => {
        if (item && Number(item.id) > 0 && item.domain) {
            merged.set(String(item.id), item);
        }
    });

    secondary.forEach((item) => {
        if (item && Number(item.id) > 0 && item.domain) {
            merged.set(String(item.id), item);
        }
    });

    return Array.from(merged.values());
};

interface Min3AvailabilitySyncProps {
    uuid: string;
    subdomain: string;
    setDomains: React.Dispatch<React.SetStateAction<DomainItem[]>>;
    setLoading: React.Dispatch<React.SetStateAction<boolean>>;
    onError: (error: unknown) => void;
}

const Min3AvailabilitySync = ({ uuid, subdomain, setDomains, onError, setLoading }: Min3AvailabilitySyncProps) => {
    useEffect(() => {
        const normalized = subdomain.trim().toLowerCase();
        if (!uuid || normalized.length < 2 || !isValidMin3LookupSubdomain(normalized)) {
            setLoading(false);
            setDomains([]);
            return;
        }

        let isCancelled = false;
        const timeout = setTimeout(() => {
            setLoading(true);
            getMin3AvailableDomains(uuid, normalized)
                .then((domains: Min3AvailableDomainItem[]) => {
                    if (isCancelled) {
                        return;
                    }

                    setDomains(domains.map((item) => ({
                        id: Number(item.id),
                        domain: String(item.domain),
                        provider: 'min3',
                    })).filter((item) => item.id > 0 && item.domain.length > 0));
                })
                .catch((error) => {
                    if (!isCancelled) {
                        setDomains([]);
                        onError(error);
                    }
                })
                .finally(() => {
                    if (!isCancelled) {
                        setLoading(false);
                    }
                });
        }, 350);

        return () => {
            isCancelled = true;
            clearTimeout(timeout);
        };
    }, [uuid, subdomain, setDomains, onError, setLoading]);

    return null;
};

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid ?? '');
    const isExternalServer = uuid.startsWith('external:');

    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const { data, error, mutate, isValidating } = useSWR<SubdomainResponse>(
        uuid || null,
        getServerSubdomains,
        {
            revalidateOnFocus: false,
        },
    );

    const [isSubmit, setSubmit] = useState(false);
    const [min3Domains, setMin3Domains] = useState<DomainItem[]>([]);
    const [isCheckingMin3, setCheckingMin3] = useState(false);
    const handleMin3Error = useCallback((availabilityError: unknown) => {
        clearAndAddHttpError({
            key: 'server:subdomain',
            error: availabilityError,
        });
    }, [clearAndAddHttpError]);

    const domainOptions = useMemo(
        () => mergeDomainOptions(data?.domains || [], min3Domains),
        [data?.domains, min3Domains],
    );

    useEffect(() => {
        if (!error) {
            clearFlashes('server:subdomain');
            return;
        }

        clearAndAddHttpError({ key: 'server:subdomain', error });
    }, [error, clearFlashes, clearAndAddHttpError]);

    useEffect(() => {
        setMin3Domains([]);
        setCheckingMin3(false);
    }, [uuid]);

    const submit = (values: CreateValues, { setSubmitting }: FormikHelpers<CreateValues>) => {
        if (!uuid) {
            return;
        }

        if (!values.domainId || Number(values.domainId) <= 0) {
            clearAndAddHttpError({
                key: 'server:subdomain',
                error: new Error('Please enter a subdomain first and select an available domain.'),
            });
            setSubmitting(false);
            return;
        }

        clearFlashes('server:subdomain');
        setSubmitting(false);
        setSubmit(true);

        createServerSubdomain(uuid, {
            subdomain: values.subdomain,
            domainId: values.domainId,
            advancedSrv: values.advancedSrv,
            srvService: values.srvService,
            srvProtocolType: values.srvProtocolType,
            srvPriority: values.srvPriority,
            srvWeight: values.srvWeight,
            srvPort: values.srvPort,
        })
            .then(() => mutate())
            .catch((submitError) => clearAndAddHttpError({ key: 'server:subdomain', error: submitError }))
            .finally(() => {
                setSubmitting(false);
                setSubmit(false);
            });
    };

    return (
        <ServerContentBlock title={'Subdomain Manager'} className={'content-dashboard'} css={tw`space-y-4`}>
            <div css={tw`w-full max-w-6xl mx-auto space-y-4`}>
                <FlashMessageRender byKey={'server:subdomain'} css={tw`mb-4`} />

                {!data ? (
                    <Spinner size={'large'} centered />
                ) : (
                    <div css={tw`space-y-4`}>
                        <div css={tw`rounded-lg border border-neutral-800 bg-neutral-900 shadow-md px-4 py-4 md:px-5`}>
                            <div css={tw`flex flex-col gap-3 md:flex-row md:items-center md:justify-between`}>
                                <div>
                                    <div css={tw`flex flex-wrap items-center gap-2`}>
                                        <h2 css={tw`text-lg md:text-xl text-neutral-100 font-semibold`}>Server Subdomains</h2>
                                        {isExternalServer && (
                                            <span
                                                css={tw`inline-flex items-center rounded-md border border-red-500/40 bg-red-500/10 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-red-300`}
                                            >
                                                External
                                            </span>
                                        )}
                                    </div>
                                    <p css={tw`text-sm text-neutral-400 mt-1`}>
                                        Create and manage DNS records for this server.
                                    </p>
                                </div>
                                <div css={tw`flex items-center gap-2`}>
                                    <span css={tw`px-3 py-1 rounded bg-neutral-800 text-neutral-300 text-xs font-medium`}>
                                        Entries: {data.subdomains.length}
                                    </span>
                                    <Button
                                        size={'xsmall'}
                                        isSecondary
                                        isLoading={isValidating}
                                        css={tw`normal-case tracking-normal`}
                                        onClick={() => mutate()}
                                    >
                                        Refresh
                                    </Button>
                                </div>
                            </div>
                        </div>

                        <div css={tw`space-y-4`}>
                            {isExternalServer && data.usingFallbackDomains ? (
                                <MessageBox type={'warning'} title={'External Mapping'}>
                                    This external server egg does not map to local domain rules, so all configured domains are shown
                                    as a fallback.
                                </MessageBox>
                            ) : null}
                            <div css={tw`grid grid-cols-1 xl:grid-cols-3 gap-4`}>
                                <div css={tw`xl:col-span-2 space-y-2`}>
                                    <TitledGreyBox title={'Create Subdomain'}>
                                        <div css={tw`px-1 py-2`}>
                                            <Formik
                                                onSubmit={submit}
                                                initialValues={{
                                                    subdomain: '',
                                                    domainId: domainOptions[0]?.id ?? 0,
                                                    advancedSrv: false,
                                                    srvService: 'minecraft',
                                                    srvProtocolType: 'tcp',
                                                    srvPriority: 1,
                                                    srvWeight: 1,
                                                    srvPort: 25565,
                                                }}
                                                validationSchema={object().shape({
                                                    subdomain: string().min(2).max(32).required(),
                                                })}
                                            >
                                                {({ values }) => {
                                                    const selectedDomain = domainOptions.find(
                                                        (item) => item.id === Number(values.domainId),
                                                    );
                                                    const selectedProvider = selectedDomain?.provider || 'cloudflare';
                                                    const normalizedSubdomain = values.subdomain.trim().toLowerCase();
                                                    const canLookupMin3 = normalizedSubdomain.length >= 2
                                                        && normalizedSubdomain.length <= 20
                                                        && isValidMin3LookupSubdomain(normalizedSubdomain);

                                                    return (
                                                        <Form>
                                                            <Min3AvailabilitySync
                                                                uuid={uuid}
                                                                subdomain={normalizedSubdomain}
                                                                setDomains={setMin3Domains}
                                                                onError={handleMin3Error}
                                                                setLoading={setCheckingMin3}
                                                            />

                                                            <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4 mb-4`}>
                                                                <Field
                                                                    name={'subdomain'}
                                                                    label={'Subdomain'}
                                                                    placeholder={'my-server'}
                                                                />
                                                                <div>
                                                                    <Label>Domain</Label>
                                                                    <FormikFieldWrapper name={'domainId'}>
                                                                        <FormikField as={Select} name={'domainId'}>
                                                                            {domainOptions.length < 1 ? (
                                                                                <option value={0}>No domain available</option>
                                                                            ) : (
                                                                                domainOptions.map((item) => (
                                                                                    <option key={item.id} value={item.id}>
                                                                                        {item.domain} ({(item.provider || 'cloudflare').toUpperCase()})
                                                                                    </option>
                                                                                ))
                                                                            )}
                                                                        </FormikField>
                                                                    </FormikFieldWrapper>
                                                                </div>
                                                            </div>

                                                            {isCheckingMin3 ? (
                                                                <p css={tw`text-xs text-neutral-400 mb-3`}>
                                                                    Checking Min3 available domains...
                                                                </p>
                                                            ) : null}

                                                            {canLookupMin3 && !isCheckingMin3 && min3Domains.length < 1 ? (
                                                                <p css={tw`text-xs text-neutral-400 mb-3`}>
                                                                    No Min3 root domain is currently available for this subdomain.
                                                                </p>
                                                            ) : null}

                                                            {domainOptions.length < 1 ? (
                                                                <MessageBox type={'info'} title={'Domain Required'}>
                                                                    Type a subdomain to fetch Min3 available domains automatically, or add
                                                                    Cloudflare domains in Admin &gt; SubDomain Manager.
                                                                </MessageBox>
                                                            ) : null}

                                                            {selectedProvider === 'min3' ? (
                                                                <MessageBox type={'warning'} title={'Min3 Provider'}>
                                                                    This domain uses Min3 API. Record creation is handled by Min3 using public IP + port.
                                                                </MessageBox>
                                                            ) : null}

                                                            <div css={tw`mb-4 rounded-md border border-neutral-700 bg-neutral-900/60 p-3`}>
                                                                <label css={tw`flex items-center gap-2 text-sm text-neutral-200`}>
                                                                    <FormikField type={'checkbox'} name={'advancedSrv'} />
                                                                    Use advanced SRV options
                                                                </label>
                                                                <p css={tw`text-xs text-neutral-400 mt-2`}>
                                                                    Forces SRV creation with custom values (even if egg mapping fallback would use
                                                                    CNAME/A).
                                                                </p>
                                                            </div>

                                                            {values.advancedSrv ? (
                                                                <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4 mb-4`}>
                                                                    {selectedProvider === 'min3' ? null : (
                                                                        <>
                                                                            <Field
                                                                                name={'srvService'}
                                                                                label={'SRV Service'}
                                                                                placeholder={'minecraft'}
                                                                            />
                                                                            <div>
                                                                                <Label>SRV Protocol Type</Label>
                                                                                <FormikFieldWrapper name={'srvProtocolType'}>
                                                                                    <FormikField as={Select} name={'srvProtocolType'}>
                                                                                        <option value={'tcp'}>TCP</option>
                                                                                        <option value={'udp'}>UDP</option>
                                                                                        <option value={'tls'}>TLS</option>
                                                                                    </FormikField>
                                                                                </FormikFieldWrapper>
                                                                            </div>
                                                                            <Field
                                                                                name={'srvPriority'}
                                                                                label={'SRV Priority'}
                                                                                type={'number'}
                                                                            />
                                                                            <Field name={'srvWeight'} label={'SRV Weight'} type={'number'} />
                                                                        </>
                                                                    )}
                                                                    <Field name={'srvPort'} label={'SRV Port'} type={'number'} />
                                                                </div>
                                                            ) : null}

                                                            <div css={tw`flex justify-end`}>
                                                                <Button
                                                                    type={'submit'}
                                                                    disabled={isSubmit || domainOptions.length < 1 || Number(values.domainId) <= 0}
                                                                >
                                                                    Create
                                                                </Button>
                                                            </div>
                                                        </Form>
                                                    );
                                                }}
                                            </Formik>
                                        </div>
                                    </TitledGreyBox>

                                    {data.subdomains.length < 1 ? (
                                        <p css={tw`text-center text-sm text-neutral-400 pt-4 pb-4`}>
                                            There are no subdomains for this server.
                                        </p>
                                    ) : (
                                        data.subdomains.map((item) => (
                                            <GreyRowBox
                                                $hoverable={false}
                                                css={tw`mt-2 flex-col gap-4 md:flex-row md:items-center md:justify-between`}
                                                key={item.id}
                                            >
                                                <div css={tw`flex items-start gap-4 min-w-0`}>
                                                    <div css={tw`text-neutral-400 pt-1`}>
                                                        <FontAwesomeIcon icon={faNetworkWired} />
                                                    </div>
                                                    <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-3 min-w-0`}>
                                                        <div css={tw`min-w-0`}>
                                                            <Code>
                                                                {item.subdomain}.{item.domain}
                                                                {item.record_type !== 'SRV' ? `:${item.port}` : ''}
                                                            </Code>
                                                            {item.record_type === 'SRV' ? (
                                                                <p css={tw`text-xs text-neutral-400 mt-1`}>
                                                                    {item.srv_service || '_service'} / _
                                                                    {item.srv_protocol_type || 'tcp'} | prio{' '}
                                                                    {item.srv_priority ?? 1} | weight {item.srv_weight ?? 1} | port{' '}
                                                                    {item.srv_port ?? item.port}
                                                                </p>
                                                            ) : null}
                                                            <Label>Subdomain</Label>
                                                        </div>
                                                        <div css={tw`min-w-0`}>
                                                            <Code>
                                                                {data.ipAlias || 'Allocation missing'}
                                                                {item.port ? `:${item.port}` : ''}
                                                            </Code>
                                                            <Label>Server Allocation</Label>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div css={tw`w-full md:w-auto text-right`}>
                                                    <DeleteSubdomainButton subdomainId={item.id} onDeleted={() => mutate()} />
                                                </div>
                                            </GreyRowBox>
                                        ))
                                    )}
                                </div>
                                <div css={tw`space-y-2`}>
                                    <TitledGreyBox title={'Help'}>
                                        <div css={tw`px-1 py-2 text-sm text-neutral-300 leading-relaxed`}>
                                            Use an alphanumeric subdomain (hyphen allowed), then pick a configured root domain. If
                                            SRV is enabled for the selected egg, the manager will create SRV records; otherwise it
                                            creates CNAME records (or A records when the server target is an IP). Min3 domains are
                                            fetched from Min3 availability for your typed subdomain.
                                        </div>
                                    </TitledGreyBox>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </ServerContentBlock>
    );
};
