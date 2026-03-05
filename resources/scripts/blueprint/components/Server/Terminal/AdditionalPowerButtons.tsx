import React from 'react';
import { useHistory, useRouteMatch } from 'react-router-dom';
import Can from '@/components/elements/Can';
import Button from '@/components/elements/Button';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faFileAlt } from '@fortawesome/free-solid-svg-icons';
/* blueprint/import */

export default () => {
    const history = useHistory();
    const match = useRouteMatch<{ id: string }>();

    const to = (value: string, url = false) => {
        if (value === '/') {
            return url ? match.url : match.path;
        }

        return `${(url ? match.url : match.path).replace(/\/*$/, '')}/${value.replace(/^\/+/, '')}`;
    };

    return (
        <>
            <Can action={'file.read-content'}>
                <Button
                    type={'button'}
                    isSecondary
                    color={'grey'}
                    className={'flex-1 flex items-center justify-center'}
                    title={'MC Logs'}
                    aria-label={'Open MC Logs'}
                    onClick={() => history.push(to('/mclogs', true))}
                >
                    <FontAwesomeIcon icon={faFileAlt} />
                </Button>
            </Can>
            {/* blueprint/react */}
        </>
    );
};
