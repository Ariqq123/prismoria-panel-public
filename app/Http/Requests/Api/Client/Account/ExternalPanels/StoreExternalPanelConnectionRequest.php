<?php

namespace Pterodactyl\Http\Requests\Api\Client\Account\ExternalPanels;

use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class StoreExternalPanelConnectionRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'panel_url' => ['required', 'string', 'url', 'max:191'],
            'websocket_origin' => ['sometimes', 'nullable', 'string', 'url', 'max:191'],
            'allowed_origin' => ['sometimes', 'nullable', 'string', 'url', 'max:191'],
            'api_key' => ['required', 'string', 'min:16', 'max:512'],
            'default_connection' => ['sometimes', 'boolean'],
        ];
    }
}
