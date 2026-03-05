import React from 'react';

/* blueprint/import *//* MclogsImportStart */import MclogsVkcpkfidyv from '@blueprint/extensions/mclogs/LogsPage';/* MclogsImportEnd *//* VotifiertesterImportStart */import VotifiertesterVakyfoihij from '@blueprint/extensions/votifiertester/VotiferTestContainer';/* VotifiertesterImportEnd *//* SubdomainmanagerImportStart */import SubdomainmanagerOsviovwkjt from '@blueprint/extensions/subdomainmanager/SubdomainContainer';/* SubdomainmanagerImportEnd */

interface RouteDefinition {
  path: string;
  name: string | undefined;
  component: React.ComponentType;
  exact?: boolean;
  adminOnly: boolean | false;
  identifier: string;
}
interface ServerRouteDefinition extends RouteDefinition {
  permission: string | string[] | null;
}
interface Routes {
  account: RouteDefinition[];
  server: ServerRouteDefinition[];
}

export default {
  account: [
    /* routes/account *//* MclogsAccountRouteStart *//* MclogsAccountRouteEnd *//* VotifiertesterAccountRouteStart *//* VotifiertesterAccountRouteEnd *//* SubdomainmanagerAccountRouteStart *//* SubdomainmanagerAccountRouteEnd */
  ],
  server: [
    /* routes/server *//* MclogsServerRouteStart */{ path: '/mclogs', permission: null, name: 'MC Logs', component: MclogsVkcpkfidyv, adminOnly: false, identifier: 'mclogs' },/* MclogsServerRouteEnd *//* VotifiertesterServerRouteStart */{ path: '/votifier', permission: 'file.read-content', name: 'Votifier', component: VotifiertesterVakyfoihij, adminOnly: false, identifier: 'votifiertester' },/* VotifiertesterServerRouteEnd *//* SubdomainmanagerServerRouteStart */{ path: '/subdomain', permission: null, name: 'Subdomain', component: SubdomainmanagerOsviovwkjt, adminOnly: false, identifier: 'subdomainmanager' },/* SubdomainmanagerServerRouteEnd */
  ],
} as Routes;
