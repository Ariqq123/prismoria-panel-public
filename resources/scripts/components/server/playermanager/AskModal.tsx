import React, { useState } from 'react';
import { Actions, useStoreActions } from 'easy-peasy';
import { ApplicationStore } from '@/state';
import Button from '@/components/elements/Button';
import ConfirmationModal from '@/components/elements/ConfirmationModal';
import tw from 'twin.macro';
import runCommand from '@/api/server/playermanager/runCommand';
import { ServerContext } from '@/state/server';
import { httpErrorToHuman } from '@/api/http';

interface Props {
    buttonText: string;
    buttonColor?: 'green' | 'red' | 'primary' | 'grey';
    buttonSecondary?: boolean;
    title: string;
    message: string;
    command: string;
    onPerformed: () => void;
}

export default ({ buttonText, buttonColor, buttonSecondary, title, message, command, onPerformed }: Props) => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);

    const [visible, setVisible] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const { clearFlashes, addError, addFlash } = useStoreActions((actions: Actions<ApplicationStore>) => actions.flashes);

    const onAllow = () => {
        setIsLoading(true);
        clearFlashes('server:playermanager');

        runCommand(uuid, command)
            .then(() => {
                setIsLoading(false);
                onPerformed();
                addFlash({
                    key: 'server:playermanager',
                    message: 'You\'ve successfully performed the action.',
                    type: 'success',
                    title: 'Success',
                });
                setVisible(false);
            })
            .catch((error) => {
                addError({ key: 'server:playermanager', message: httpErrorToHuman(error) });
                setIsLoading(false);
                setVisible(false);
            });
    };

    return (
        <>
            <ConfirmationModal
                visible={visible}
                title={title}
                buttonText={'Yes'}
                onConfirmed={onAllow}
                showSpinnerOverlay={isLoading}
                onModalDismissed={() => setVisible(false)}
            >
                <p dangerouslySetInnerHTML={{ __html: message }} />
            </ConfirmationModal>
            <Button
                color={buttonColor ?? 'red'}
                isSecondary={buttonSecondary}
                size={'xsmall'}
                css={[tw`m-0 normal-case tracking-normal whitespace-nowrap flex-1 sm:flex-none`, { minWidth: '6.5rem' }]}
                onClick={() => setVisible(true)}
            >
                {buttonText}
            </Button>
        </>
    );
};
