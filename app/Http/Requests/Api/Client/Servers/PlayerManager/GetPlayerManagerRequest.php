<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\PlayerManager;

use Pterodactyl\Models\Permission;
use Pterodactyl\Contracts\Http\ClientPermissionsRequest;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class GetPlayerManagerRequest extends ClientApiRequest implements ClientPermissionsRequest
{
    public function permission(): string
    {
        return Permission::ACTION_CONTROL_CONSOLE;
    }
}
