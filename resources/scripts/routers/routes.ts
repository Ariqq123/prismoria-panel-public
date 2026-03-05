import { IconProp } from '@fortawesome/fontawesome-svg-core';
import React, { lazy } from 'react';
import ServerConsole from '@/components/server/console/ServerConsoleContainer';
import DatabasesContainer from '@/components/server/databases/DatabasesContainer';
import ScheduleContainer from '@/components/server/schedules/ScheduleContainer';
import UsersContainer from '@/components/server/users/UsersContainer';
import BackupContainer from '@/components/server/backups/BackupContainer';
import NetworkContainer from '@/components/server/network/NetworkContainer';
import StartupContainer from '@/components/server/startup/StartupContainer';
import FileManagerContainer from '@/components/server/files/FileManagerContainer';
import SettingsContainer from '@/components/server/settings/SettingsContainer';
import AccountOverviewContainer from '@/components/dashboard/AccountOverviewContainer';
import AccountApiContainer from '@/components/dashboard/AccountApiContainer';
import AccountSSHContainer from '@/components/dashboard/ssh/AccountSSHContainer';
import ActivityLogContainer from '@/components/dashboard/activity/ActivityLogContainer';
import ExternalPanelConnectionsContainer from '@/components/dashboard/ExternalPanelConnectionsContainer';
import ServerActivityLogContainer from '@/components/server/ServerActivityLogContainer';
import PluginsManagerContainer from '@/components/server/mcplugins/PluginsManagerContainer';
import PlayerManagerContainer from '@/components/server/playermanager/PlayerManagerContainer';
import ServerStatusContainer from '@/components/server/status/ServerStatusContainer';
import VersionChangerContainer from '@/components/server/versionchanger/VersionChangerContainer';
import AutoBackupContainer from '@/components/server/autobackups/AutoBackupContainer';
import {
    faBackward,
    faCodeBranch,
    faClock,
    faCloudUploadAlt,
    faCogs,
    faCubes,
    faDatabase,
    faEdit,
    faFolder,
    faKey,
    faNetworkWired,
    faPaperclip,
    faPassport,
    faPlug,
    faPlayCircle,
    faSignal,
    faTerminal,
    faUser,
    faUsers,
} from '@fortawesome/free-solid-svg-icons';

// Each of the router files is already code split out appropriately — so
// all of the items above will only be loaded in when that router is loaded.
//
// These specific lazy loaded routes are to avoid loading in heavy screens
// for the server dashboard when they're only needed for specific instances.
const FileEditContainer = lazy(() => import('@/components/server/files/FileEditContainer'));
const ScheduleEditContainer = lazy(() => import('@/components/server/schedules/ScheduleEditContainer'));

interface RouteDefinition {
    path: string;
    // If undefined is passed this route is still rendered into the router itself
    // but no navigation link is displayed in the sub-navigation menu.
    name: string | undefined;
    component: React.ComponentType;
    exact?: boolean;
    iconProp?: IconProp;
}

interface ServerRouteDefinition extends RouteDefinition {
    permission: string | string[] | null;
    externalCapability?: string;
}

interface Routes {
    // All of the routes available under "/account"
    account: RouteDefinition[];
    // All of the routes available under "/server/:id"
    server: ServerRouteDefinition[];
}

export default {
    account: [
        {
            path: '/',
            name: 'Account',
            component: AccountOverviewContainer,
            exact: true,
            iconProp: faUser,
        },
        {
            path: '/api',
            name: 'API Credentials',
            component: AccountApiContainer,
            iconProp: faPassport,
        },
        {
            path: '/ssh',
            name: 'SSH Keys',
            component: AccountSSHContainer,
            iconProp: faKey,
        },
        {
            path: '/activity',
            name: 'Activity',
            component: ActivityLogContainer,
            iconProp: faPaperclip,
        },
        {
            path: '/external-panels',
            name: 'External APIs',
            component: ExternalPanelConnectionsContainer,
            iconProp: faPlug,
        },
    ],
    server: [
        {
            path: '/',
            permission: null,
            name: 'Console',
            component: ServerConsole,
            exact: true,
            iconProp: faTerminal,
            externalCapability: 'resources',
        },
        {
            path: '/files',
            permission: 'file.*',
            name: 'Files',
            component: FileManagerContainer,
            iconProp: faFolder,
            externalCapability: 'files',
        },
        {
            path: '/mcplugins',
            permission: 'file.*',
            name: 'Plugins',
            component: PluginsManagerContainer,
            iconProp: faCubes,
            externalCapability: 'files',
        },
        {
            path: '/playermanager',
            permission: 'control.console',
            name: 'Player Manager',
            component: PlayerManagerContainer,
            iconProp: faUsers,
            externalCapability: 'command',
        },
        {
            path: '/status',
            permission: null,
            name: 'Status',
            component: ServerStatusContainer,
            iconProp: faSignal,
            externalCapability: 'resources',
        },
        {
            path: '/versionchanger',
            permission: ['file.read', 'file.create'],
            name: 'Version Changer',
            component: VersionChangerContainer,
            iconProp: faCodeBranch,
            externalCapability: 'files',
        },
        {
            path: '/files/:action(edit|new)',
            permission: 'file.*',
            name: undefined,
            component: FileEditContainer,
            iconProp: faEdit,
        },
        {
            path: '/databases',
            permission: 'database.*',
            name: 'Databases',
            component: DatabasesContainer,
            iconProp: faDatabase,
            externalCapability: 'databases',
        },
        {
            path: '/schedules',
            permission: 'schedule.*',
            name: 'Schedules',
            component: ScheduleContainer,
            iconProp: faClock,
            externalCapability: 'schedules',
        },
        {
            path: '/schedules/:id',
            permission: 'schedule.*',
            name: undefined,
            component: ScheduleEditContainer,
            iconProp: faClock,
        },
        {
            path: '/users',
            permission: 'user.*',
            name: 'Users',
            component: UsersContainer,
            iconProp: faUser,
            externalCapability: 'users',
        },
        {
            path: '/backups',
            permission: 'backup.*',
            name: 'Backups',
            component: BackupContainer,
            iconProp: faBackward,
            externalCapability: 'backups',
        },
        {
            path: '/autobackup',
            permission: 'backup.*',
            name: 'Auto Backup',
            component: AutoBackupContainer,
            iconProp: faCloudUploadAlt,
            externalCapability: 'backups',
        },
        {
            path: '/network',
            permission: 'allocation.*',
            name: 'Network',
            component: NetworkContainer,
            iconProp: faNetworkWired,
            externalCapability: 'network',
        },
        {
            path: '/startup',
            permission: 'startup.*',
            name: 'Startup',
            component: StartupContainer,
            iconProp: faPlayCircle,
            externalCapability: 'startup',
        },
        {
            path: '/settings',
            permission: ['settings.*', 'file.sftp'],
            name: 'Settings',
            component: SettingsContainer,
            iconProp: faCogs,
            externalCapability: 'settings.rename',
        },
        {
            path: '/activity',
            permission: 'activity.*',
            name: 'Activity',
            component: ServerActivityLogContainer,
            iconProp: faPaperclip,
            externalCapability: 'activity',
        },
    ],
} as Routes;
