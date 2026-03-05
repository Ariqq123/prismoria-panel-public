@extends('layouts.admin')
<?php 
    // Define extension information.
    $EXTENSION_ID = "serverbackgrounds";
    $EXTENSION_NAME = stripslashes("Server Backgrounds");
    $EXTENSION_VERSION = "v1.6.0";
    $EXTENSION_DESCRIPTION = stripslashes("Server Container Backgrounds for Pterodactyl using Blueprint!");
    $EXTENSION_ICON = "/assets/extensions/serverbackgrounds/icon.jpg";
    $EXTENSION_WEBSITE = "https://euphoriadevelopment.uk";
    $EXTENSION_WEBICON = "bi bi-link-45deg";
?>
@include('blueprint.admin.template')

@section('title')
    {{ $EXTENSION_NAME }}
@endsection

@section('content-header')
    @yield('extension.header')
@endsection

@section('content')
    @yield('extension.config')
    @yield('extension.description')<?php
    $disable_for_admins = $blueprint->dbGet("serverbackgrounds", 'disable_for_admins');
?>
<style>
    /* Container styles */
    .box {
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        margin-bottom: 1.5rem;
    }

    .box-header {
        padding: 15px 20px;
        border-bottom: 1px solid #e9ecef;
        border-top-left-radius: 8px;
        border-top-right-radius: 8px;
    }

    .box-title {
        font-size: 1.25rem;
        font-weight: bold;
        color: var(--white);
    }

    .box-body {
        padding: 20px;
    }

    .flex-grow-1 {
        width: 80% !important;
    }

    /* Form elements */
    .form-group {
        margin-bottom: 1.5rem;
    }

    .form-group label {
        font-weight: bold;
        color: var(--white);
    }

    .form-control {
        border-radius: 5px;
        border: 1px solid #ced4da;
        padding: 10px;
        transition: border-color 0.2s;
        width: 80% !important;
    }

    .form-control:focus {
        border-color: #80bdff;
        box-shadow: 0 0 5px rgba(0, 123, 255, 0.25);
    }

    /* Buttons */
    .btn-primary {
        background-color: #007bff;
        border-color: #007bff;
        transition: background-color 0.2s, border-color 0.2s;
    }

    .btn-primary:hover {
        background-color: #0056b3;
        border-color: #004085;
    }

    /* List group styles */
    .list-group-item {
        display: flex;
        align-items: center;
        padding: 1rem;
        border-radius: 8px;
        background-color: var(--gray-900) !important;
        margin-bottom: 10px;
        transition: background-color 0.2s;
    }

    .list-group-item:hover {
        background-color: #e9ecef;
    }

    /* Image styling */
    .list-group-item img {
        width: 100%; /* Ensures the image takes up the full width of its container */
        max-width: 584px; /* Limits width */
        max-height: 155px; /* Limits height */
        border-radius: 8px;
        margin-top: 0.5rem;
        object-fit: cover; /* Ensures the image scales and crops to fit */
        object-position: center; /* Centers the image content within the frame */
    }

    /* Checkbox styling */
    .form-check-label {
        font-weight: bold;
        margin-left: 5px;
        color: #d9534f;
        cursor: pointer;
    }

    /* Add space around delete checkbox */
    .delete-checkbox-container {
        margin-left: 0.25rem;
    }

    .input[type=range] {
        display: block;
        width: 80% !important;
    }

    .form-control-range {
        display: block;
        width: 80% !important;
    }
</style>

<!-- Version and Information Box (Full Width) -->
<div class="row">
    <div class="col-xs-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title"><strong>Server Backgrounds</strong> by <strong>Euphoria Development</strong></h3>
            </div>
            <div class="box-body">
                Identifier: <code>serverbackgrounds</code><br>
                Uninstall using: <code>blueprint -remove serverbackgrounds</code><br>
                Get support via <a href="https://discord.gg/Cus2zP4pPH" target="_blank" rel="noopener noreferrer">Discord</a><br>
                Want an easier setup? Host your Images using <a href="https://github.com/EuphoriaTheme/Resource-Manager" target="_blank" rel="noopener noreferrer">Resource Manager</a><br>
            </div>
        </div>
    </div>
</div>

<br>

<!-- Addon Settings (Performance) -->
<div class="box box-primary">
    <div class="box-header with-border">
        <h3 class="box-title">Addon Settings</h3>
    </div>
    <form action="{{ route('blueprint.extensions.serverbackgrounds.updateSettings') }}" method="POST">
        @csrf
        <div class="box-body">
            <div class="form-group">
                <label>Disable Backgrounds for Admin Users</label><br>
                <small style="color: var(--gray-300);">
                    Recommended for large installations. When enabled, root admins will not see server backgrounds.
                </small>
                <div class="form-check form-check-inline" style="margin-top: 10px;">
                    <input class="form-check-input" type="radio" name="disable_for_admins" id="disable_for_admins_yes" value="1" {{ old('disable_for_admins', $disable_for_admins ?? false) ? 'checked' : '' }}>
                    <label class="form-check-label" for="disable_for_admins_yes" style="color: var(--white);">Yes</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="disable_for_admins" id="disable_for_admins_no" value="0" {{ old('disable_for_admins', $disable_for_admins ?? false) ? '' : 'checked' }}>
                    <label class="form-check-label" for="disable_for_admins_no" style="color: var(--white);">No</label>
                </div>
            </div>
        </div>
        <div class="box-footer">
            <button type="submit" class="btn btn-primary mt-2">Save Settings</button>
        </div>
    </form>
</div>
<!-- Bulk Set Backgrounds Form -->
<div class="box box-primary">
    <div class="box-header with-border">
        <h3 class="box-title">Bulk Set Server Backgrounds</h3>
    </div>
    <form action="{{ route('blueprint.extensions.serverbackgrounds.bulkSaveSettings') }}" method="POST">
        @csrf
        <div class="box-body" id="bulk-backgrounds-container">
            <div class="form-group">
                <label for="backgrounds[0][server_id]">Server Identifier:</label>
                <select name="backgrounds[0][server_id]" class="form-control">
                    <option value="">Select Server (Optional)</option>
                    <optgroup label="Local Servers">
                        @foreach ($servers as $server)
                            <option value="{{ $server->uuid }}">{{ $server->name }} ({{ $server->uuid }})</option>
                        @endforeach
                    </optgroup>
                    @if (!empty($externalServers))
                        <optgroup label="External Servers">
                            @foreach ($externalServers as $externalServer)
                                <option value="{{ $externalServer['id'] }}">{{ $externalServer['name'] }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
                <small style="color: var(--gray-300); display: block; margin-top: 6px;">
                    External servers use identifiers like <code>external:CONNECTION_ID:SERVER_ID</code>.
                </small>
            </div>
            <div class="form-group">
                <label for="backgrounds[0][egg_id]">Egg:</label>
                <select name="backgrounds[0][egg_id]" class="form-control">
                    <option value="">Select Egg</option>
                    @foreach ($eggs as $egg)
                        <option value="{{ $egg->id }}">{{ $egg->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label for="backgrounds[0][image_url]">Image URL:</label>
                <input type="text" name="backgrounds[0][image_url]" class="form-control" placeholder="Enter Image URL">
            </div>
        </div>        
        <div class="box-footer">
            <button type="button" class="btn btn-primary mt-2" onclick="addBackgroundField()">Add Another Background</button>
            <button type="submit" class="btn btn-primary mt-2">Save All Backgrounds</button>
        </div>
    </form>
</div>

<script>
    let backgroundCount = 1;
    function addBackgroundField() {
        const container = document.getElementById('bulk-backgrounds-container');
        const newField = document.createElement('div');
        newField.innerHTML = `
            <div class="form-group">
                <label for="backgrounds[${backgroundCount}][server_id]">Server Identifier:</label>
                <select name="backgrounds[${backgroundCount}][server_id]" class="form-control">
                    <option value="">Select Server (Optional)</option>
                    <optgroup label="Local Servers">
                        @foreach ($servers as $server)
                            <option value="{{ $server->uuid }}">{{ $server->name }} ({{ $server->uuid }})</option>
                        @endforeach
                    </optgroup>
                    @if (!empty($externalServers))
                        <optgroup label="External Servers">
                            @foreach ($externalServers as $externalServer)
                                <option value="{{ $externalServer['id'] }}">{{ $externalServer['name'] }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
            </div>
            <div class="form-group">
                <label for="backgrounds[${backgroundCount}][egg_id]">Egg:</label>
                <select name="backgrounds[${backgroundCount}][egg_id]" class="form-control">
                    <option value="">Select Egg</option>
                    @foreach ($eggs as $egg)
                        <option value="{{ $egg->id }}">{{ $egg->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label for="backgrounds[${backgroundCount}][image_url]">Image URL:</label>
                <input type="text" name="backgrounds[${backgroundCount}][image_url]" class="form-control" placeholder="Enter Image URL">
            </div>
        `;
        container.appendChild(newField);
        backgroundCount++;
    }
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const debounce = (func, delay) => {
        let timeout;
        return (...args) => {
            clearTimeout(timeout);
            timeout = setTimeout(() => func(...args), delay);
        };
    };

    const updateServerBackgrounds = debounce(async () => {
        const servers = @json($servers);
        const eggs = @json($eggs);

        document.querySelectorAll('.server-container').forEach((container) => {
            const serverUuid = container.getAttribute('data-server-uuid');
            const server = servers.find(s => s.uuid === serverUuid);
            const egg = server ? eggs.find(e => e.id === server.egg_id) : null;

            let backgroundImageUrl = '';

            if (server && server.background_image_url) {
                backgroundImageUrl = server.background_image_url;
            } else if (egg && egg.background_image_url) {
                backgroundImageUrl = egg.background_image_url;
            }

            if (backgroundImageUrl) {
                container.style.backgroundImage = `url('${backgroundImageUrl}')`;
                container.style.backgroundSize = 'cover';
                container.style.backgroundPosition = 'center center';
            }
        });
    }, 500);

    updateServerBackgrounds();
});
</script>

<!-- Update and Delete Background Images Form -->
<div class="box box-primary mt-4">
    <div class="box-header with-border">
        <h3 class="box-title">Edit or Delete Applied Backgrounds</h3>
    </div>
    <form action="{{ route('blueprint.extensions.serverbackgrounds.updateAndDeleteSettings') }}" method="POST">
        @csrf
        <div class="box-body">
            <ul class="list-group">
                @foreach ($configuredServers as $configured)
                    @php($serverIdentifier = $configured->identifier ?? $configured->uuid)
                    <li class="list-group-item">
                        <div class="flex-grow-1">
                            <strong>{{ $configured->name }} ({{ $serverIdentifier }})</strong>
                            <div class="form-group mt-2">
                                <label for="backgrounds[{{ $serverIdentifier }}][image_url]">Image URL:</label>
                                <input type="text" name="backgrounds[{{ $serverIdentifier }}][image_url]" class="form-control" value="{{ $configured->image_url }}">
                                <input type="hidden" name="backgrounds[{{ $serverIdentifier }}][server_id]" value="{{ $serverIdentifier }}">
                            </div>
                            <div class="form-group mt-2">
                                <label for="backgrounds[{{ $serverIdentifier }}][opacity]">Transparency:</label>
                                <input type="range" name="backgrounds[{{ $serverIdentifier }}][opacity]" class="form-control-range" min="0" max="1" step="0.1" 
                                    value="{{ $configured->opacity ?? 1 }}" 
                                    onchange="updateImageOpacity(this, '{{ $serverIdentifier }}')">
                            </div>
                            <!-- Image preview with saved opacity -->
                            <img id="image-{{ $serverIdentifier }}" src="{{ $configured->image_url }}" alt="Background for {{ $configured->name }}" style="opacity: {{ $configured->opacity ?? 1 }};">
                        </div>
                        <!-- Checkbox for bulk delete -->
                        <div class="form-check delete-checkbox-container">
                            <input class="form-check-input" type="checkbox" name="delete_backgrounds[]" value="{{ $serverIdentifier }}" id="delete_{{ $serverIdentifier }}">
                            <label class="form-check-label" for="delete_{{ $serverIdentifier }}">
                                Delete
                            </label>
                        </div>
                    </li>
                @endforeach

                @foreach ($configuredEggs as $configured)
                    <li class="list-group-item">
                        <div class="flex-grow-1">
                            <strong>{{ $configured->name }} (Egg)</strong>
                            <div class="form-group mt-2">
                                <label for="backgrounds[{{ $configured->id }}][image_url]">Image URL:</label>
                                <input type="text" name="backgrounds[{{ $configured->id }}][image_url]" class="form-control" value="{{ $configured->image_url }}">
                                <input type="hidden" name="backgrounds[{{ $configured->id }}][egg_id]" value="{{ $configured->id }}">
                            </div>
                            <div class="form-group mt-2">
                                <label for="backgrounds[{{ $configured->id }}][opacity]">Transparency:</label>
                                <input type="range" name="backgrounds[{{ $configured->id }}][opacity]" class="form-control-range" min="0" max="1" step="0.1" 
                                    value="{{ $configured->opacity ?? 1 }}" 
                                    onchange="updateImageOpacity(this, '{{ $configured->id }}')">
                            </div>
                            <!-- Image preview with saved opacity -->
                            <img id="image-{{ $configured->id }}" src="{{ $configured->image_url }}" alt="Background for {{ $configured->name }}" style="opacity: {{ $configured->opacity ?? 1 }};">
                        </div>
                        <!-- Checkbox for bulk delete -->
                        <div class="form-check delete-checkbox-container">
                            <input class="form-check-input" type="checkbox" name="delete_backgrounds[]" value="{{ $configured->id }}" id="delete_{{ $configured->id }}">
                            <label class="form-check-label" for="delete_{{ $configured->id }}">
                                Delete
                            </label>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
        <div class="box-footer">
            <button type="submit" class="btn btn-primary mt-2">Save Changes</button>
        </div>
    </form>
</div>

<script>
    function updateImageOpacity(rangeInput, id) {
        const image = document.getElementById(`image-${id}`);
        image.style.opacity = rangeInput.value;
    }

    function deleteBackground(id) {
        const container = document.getElementById('edit-delete-backgrounds-container');
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = `delete_backgrounds[]`;
        input.value = id;
        container.appendChild(input);
    }
</script>
@endsection
