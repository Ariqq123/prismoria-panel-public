import React from 'react';
import { NavLink, useRouteMatch } from 'react-router-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faNetworkWired, faPlug } from '@fortawesome/free-solid-svg-icons';
import Can from '@/components/elements/Can';
import { ServerContext } from '@/state/server';
/* blueprint/import */

export default () => {
  const match = useRouteMatch<{ id: string }>();
  const serverIdentifier = ServerContext.useStoreState((state) => state.server.data?.id ?? '');
  const isExternalServer = serverIdentifier.startsWith('external:');

  const to = (value: string, url = false) => {
    if (value === '/') {
      return url ? match.url : match.path;
    }

    return `${(url ? match.url : match.path).replace(/\/*$/, '')}/${value.replace(/^\/+/, '')}`;
  };

  const item = (
    <NavLink to={to('/subdomain', true)} exact>
      <div className={'icon'}>
        <FontAwesomeIcon icon={faNetworkWired} />
      </div>
      Subdomain
    </NavLink>
  );

  const votifierItem = (
    <NavLink to={to('/votifier', true)} exact>
      <div className={'icon'}>
        <FontAwesomeIcon icon={faPlug} />
      </div>
      Votifier
    </NavLink>
  );

  return (
    <>
      {isExternalServer ? item : <Can action={'subdomain.manage'}>{item}</Can>}
      {isExternalServer ? votifierItem : <Can action={'file.read-content'}>{votifierItem}</Can>}
      {/* blueprint/react */}
    </>
  );
};
