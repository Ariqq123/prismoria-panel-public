<?php

namespace Pterodactyl\Http\Requests\Admin\Settings;

use Illuminate\Validation\Rule;
use Pterodactyl\Traits\Helpers\AvailableLanguages;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

class BaseSettingsFormRequest extends AdminFormRequest
{
    use AvailableLanguages;

    public function rules(): array
    {
        return [
            'app:name' => 'required|string|max:191',
            'pterodactyl:auth:2fa_required' => 'required|integer|in:0,1,2',
            'app:locale' => ['required', 'string', Rule::in(array_keys($this->getAvailableLanguages()))],
            'pterodactyl:ui:background_image' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp,mp4,webm|max:51200',
            'pterodactyl:ui:background_image_remove' => 'nullable|boolean',
        ];
    }

    public function attributes(): array
    {
        return [
            'app:name' => 'Company Name',
            'pterodactyl:auth:2fa_required' => 'Require 2-Factor Authentication',
            'app:locale' => 'Default Language',
            'pterodactyl:ui:background_image' => 'Panel Background Media',
            'pterodactyl:ui:background_image_remove' => 'Remove Panel Background Media',
        ];
    }
}
