import React, { useState } from 'react';
import { ServerContext } from '@/state/server';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import { sendClassicVotifierVote, sendNuVotifierVote, sendNuVotifierV2Vote } from './api/sendVote';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import { Formik, Form, Field as FormikField, FormikHelpers } from 'formik';
import * as Yup from 'yup';
import tw from 'twin.macro';
import Label from '@/components/elements/Label';
import FormikFieldWrapper from '@/components/elements/FormikFieldWrapper';
import Select from '@/components/elements/Select';
import Field from '@/components/elements/Field';
import Button from '@/components/elements/Button';
import FlashMessageRender from '@/components/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import MessageBox from '@/components/MessageBox';
import styled, { keyframes } from 'styled-components/macro';

const borderFlow = keyframes`
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
`;

const auroraText = keyframes`
    0% { background-position: 0% 50%; }
    50% { background-position: 100% 50%; }
    100% { background-position: 0% 50%; }
`;

const MainLayout = styled.div`
    ${tw`w-full max-w-5xl mx-auto space-y-4`};
`;

const MagicCard = styled.div<{ $interactive?: boolean }>`
    ${tw`relative overflow-hidden rounded-xl border p-4 md:p-5`};
    border-color: var(--panel-border);
    background: var(--panel-magic-card-bg);
    box-shadow: var(--panel-magic-card-shadow);
    transition: transform 240ms cubic-bezier(0.22, 1, 0.36, 1), border-color 220ms ease, box-shadow 220ms ease;

    &::before {
        content: '';
        position: absolute;
        inset: -35% -12%;
        pointer-events: none;
        background: var(--panel-magic-card-glow);
    }

    ${({ $interactive }) =>
        $interactive
            ? `
        &:hover {
            transform: translateY(-2px);
            border-color: var(--panel-magic-accent-border);
            box-shadow: var(--panel-magic-card-shadow-hover);
        }
    `
            : ''}
`;

const ShineBorder = styled.div`
    position: absolute;
    inset: 0;
    border-radius: inherit;
    pointer-events: none;
    border: 1px solid transparent;
    background: var(--panel-magic-border-gradient) border-box;
    background-size: 250% 250%;
    animation: ${borderFlow} 6s ease infinite;
    -webkit-mask: linear-gradient(#fff 0 0) padding-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
`;

const HeroTitle = styled.h2`
    ${tw`text-lg md:text-xl font-semibold tracking-wide`};
    background: var(--panel-magic-title-gradient);
    background-size: 220% 220%;
    animation: ${auroraText} 7.2s ease infinite;
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
`;

interface Values {
    host: string;
    port: string;
    publicKey: string;
    token: string;
    username: string;
    voteType: 'classic' | 'nuvotifier' | 'nuvotifierv2';
}

const VotifierTestContainer: React.FC = () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid ?? '');
    const [isLoading, setIsLoading] = useState(false);
    const { addFlash, clearFlashes, clearAndAddHttpError } = useFlash();

    const submit = async (values: Values, { setSubmitting }: FormikHelpers<Values>) => {
        if (!uuid) {
            return;
        }

        setIsLoading(true);
        clearFlashes('server:votifier');

        try {
            const response =
                values.voteType === 'classic'
                    ? await sendClassicVotifierVote(uuid, values.host, values.port, values.publicKey, values.username)
                    : values.voteType === 'nuvotifier'
                        ? await sendNuVotifierVote(uuid, values.host, values.port, values.publicKey, values.username)
                        : await sendNuVotifierV2Vote(uuid, values.host, values.port, values.token, values.username);

            addFlash({
                key: 'server:votifier',
                type: 'success',
                message: response?.data?.message || 'Vote sent successfully!',
            });
        } catch (error) {
            clearAndAddHttpError({ key: 'server:votifier', error });
        } finally {
            setIsLoading(false);
            setSubmitting(false);
        }
    };

    return (
        <ServerContentBlock title={'Votifier Tester'}>
            <MainLayout>
                <FlashMessageRender byKey={'server:votifier'} css={tw`mb-4`} />

                <MagicCard>
                    <ShineBorder />
                    <div css={tw`relative z-10`}>
                        <HeroTitle>Minecraft Vote Sender</HeroTitle>
                        <p css={tw`text-sm text-neutral-300 mt-1`}>
                            Send a test vote to your server directly from the panel without shell access.
                        </p>
                    </div>
                </MagicCard>

                <Formik<Values>
                    initialValues={{
                        host: '',
                        port: '8192',
                        publicKey: '',
                        token: '',
                        username: '',
                        voteType: 'classic',
                    }}
                    validationSchema={Yup.object().shape({
                        host: Yup.string().required('Host is required'),
                        port: Yup.string().required('Port is required'),
                        publicKey: Yup.string().when('voteType', {
                            is: (val: string) => val !== 'nuvotifierv2',
                            then: Yup.string().required('Public Key is required'),
                        }),
                        token: Yup.string().when('voteType', {
                            is: 'nuvotifierv2',
                            then: Yup.string().required('Token is required'),
                        }),
                        username: Yup.string().required('Username is required'),
                        voteType: Yup.string().required('Vote Type is required'),
                    })}
                    onSubmit={submit}
                >
                    {({ values, isSubmitting }) => (
                        <MagicCard $interactive>
                            <TitledGreyBox title={'Send Test Vote'} css={tw`relative`}>
                                <SpinnerOverlay visible={isLoading || isSubmitting} />
                                <Form css={tw`mb-0 space-y-4`}>
                                <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-4`}>
                                    <Field
                                        id={'votifier-host'}
                                        name={'host'}
                                        type={'text'}
                                        label={'Host'}
                                        placeholder={'127.0.0.1 or play.example.com'}
                                    />
                                    <Field
                                        id={'votifier-port'}
                                        name={'port'}
                                        type={'number'}
                                        label={'Port'}
                                        placeholder={'8192'}
                                    />
                                </div>

                                <Field
                                    id={'votifier-username'}
                                    name={'username'}
                                    type={'text'}
                                    label={'Username'}
                                    placeholder={'PlayerName'}
                                />

                                <div>
                                    <Label htmlFor={'votifier-vote-type'}>Vote Type</Label>
                                    <FormikFieldWrapper name={'voteType'}>
                                        <FormikField as={Select} id={'votifier-vote-type'} name={'voteType'}>
                                            <option value={'classic'}>Classic Votifier (Public Key)</option>
                                            <option value={'nuvotifier'}>NuVotifier (Public Key)</option>
                                            <option value={'nuvotifierv2'}>NuVotifier v2 (Token)</option>
                                        </FormikField>
                                    </FormikFieldWrapper>
                                </div>

                                {values.voteType === 'nuvotifierv2' ? (
                                    <Field
                                        id={'votifier-token'}
                                        name={'token'}
                                        type={'text'}
                                        label={'Token'}
                                        placeholder={'Enter NuVotifier v2 token'}
                                    />
                                ) : (
                                    <Field
                                        id={'votifier-public-key'}
                                        name={'publicKey'}
                                        type={'text'}
                                        label={'Public Key'}
                                        placeholder={'Paste plugins/Votifier/rsa/public.key content'}
                                    />
                                )}

                                <div
                                    css={tw`rounded-md border p-3`}
                                    style={{ borderColor: 'var(--panel-chip-border)', background: 'var(--panel-chip-bg)' }}
                                >
                                    <p css={tw`text-xs text-neutral-400 leading-relaxed`}>
                                        Public key: <code css={tw`text-neutral-200`}>plugins/Votifier/rsa/public.key</code>
                                        <br />
                                        Token: <code css={tw`text-neutral-200`}>plugins/Votifier/config.yml</code>
                                    </p>
                                </div>

                                <div css={tw`flex justify-end`}>
                                    <Button type={'submit'} disabled={isSubmitting || isLoading}>
                                        Send Test Vote
                                    </Button>
                                </div>
                                </Form>
                            </TitledGreyBox>
                        </MagicCard>
                    )}
                </Formik>

                <MagicCard $interactive>
                    <MessageBox type={'info'} title={'Supported Modes'}>
                        Classic Votifier, NuVotifier (public key), and NuVotifier v2 (token) are supported.
                    </MessageBox>
                </MagicCard>
            </MainLayout>
        </ServerContentBlock>
    );
};

export default VotifierTestContainer;
