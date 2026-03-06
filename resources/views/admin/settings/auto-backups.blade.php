@extends('layouts.admin')
@include('partials/admin.settings.nav', ['activeTab' => 'auto-backups'])

@section('title')
    Auto Backup Settings
@endsection

@section('content-header')
    <h1>Auto Backup Settings<small>Configure global defaults and destination credentials.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Settings</li>
    </ol>
@endsection

@section('content')
    @yield('settings::nav')
    <div class="row">
        <div class="col-xs-12">
            <form action="{{ route('admin.settings.auto-backups.update') }}" method="POST">
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Global Policy</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-3">
                                <label class="control-label">Enable Auto Backups</label>
                                <div>
                                    <input type="hidden" name="auto_backups:enabled" value="0" />
                                    <input type="checkbox" name="auto_backups:enabled" value="1" @if(old('auto_backups:enabled', $settings['enabled'] ? '1' : '0') === '1') checked @endif />
                                    <p class="text-muted small">If disabled, profiles will remain visible but runs and scheduler processing are blocked.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-3">
                                <label class="control-label">Allow User Credential Override</label>
                                <div>
                                    <input type="hidden" name="auto_backups:allow_user_destination_override" value="0" />
                                    <input type="checkbox" name="auto_backups:allow_user_destination_override" value="1" @if(old('auto_backups:allow_user_destination_override', $settings['allow_user_destination_override'] ? '1' : '0') === '1') checked @endif />
                                    <p class="text-muted small">If off, required credentials from this page are enforced on all profiles.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-3">
                                <label class="control-label">Default Destination</label>
                                <select class="form-control" name="auto_backups:default_destination_type">
                                    @php($destination = old('auto_backups:default_destination_type', $settings['default_destination_type']))
                                    <option value="google_drive" @if($destination === 'google_drive') selected @endif>Google Drive</option>
                                    <option value="s3" @if($destination === 's3') selected @endif>S3 Bucket</option>
                                    <option value="dropbox" @if($destination === 'dropbox') selected @endif>Dropbox</option>
                                </select>
                            </div>
                            <div class="form-group col-md-3">
                                <label class="control-label">Default Interval (Minutes)</label>
                                <input
                                    type="number"
                                    min="5"
                                    max="10080"
                                    class="form-control"
                                    name="auto_backups:default_interval_minutes"
                                    value="{{ old('auto_backups:default_interval_minutes', $settings['default_interval_minutes']) }}"
                                />
                            </div>
                            <div class="form-group col-md-3">
                                <label class="control-label">Default Keep Remote Copies</label>
                                <input
                                    type="number"
                                    min="1"
                                    max="1000"
                                    class="form-control"
                                    name="auto_backups:default_keep_remote"
                                    value="{{ old('auto_backups:default_keep_remote', $settings['default_keep_remote']) }}"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Google Drive Defaults</h3>
                    </div>
                    <div class="box-body">
                        @php($googleAuthMode = old('auto_backups:google_drive:auth_mode', $settings['destinations']['google_drive']['auth_mode'] ?? 'oauth'))
                        <div class="row">
                            <div class="form-group col-md-4">
                                <label class="control-label">Auth Mode</label>
                                <select class="form-control" name="auto_backups:google_drive:auth_mode">
                                    <option value="service_account" @if($googleAuthMode === 'service_account') selected @endif>Service Account (Recommended)</option>
                                    <option value="oauth" @if($googleAuthMode === 'oauth') selected @endif>OAuth Client + Refresh Token</option>
                                </select>
                            </div>
                            <div class="form-group col-md-4">
                                <label class="control-label">Folder ID <span class="field-optional"></span></label>
                                <input type="text" class="form-control" name="auto_backups:google_drive:folder_id" value="{{ old('auto_backups:google_drive:folder_id', $settings['destinations']['google_drive']['folder_id']) }}" />
                            </div>
                            <div class="form-group col-md-4">
                                <label class="control-label">Client ID</label>
                                <input type="text" class="form-control" name="auto_backups:google_drive:client_id" value="{{ old('auto_backups:google_drive:client_id', $settings['destinations']['google_drive']['client_id']) }}" />
                            </div>
                            <div class="form-group col-md-12">
                                <label class="control-label">Service Account JSON <span class="field-optional"></span></label>
                                <textarea class="form-control" rows="6" name="auto_backups:google_drive:service_account_json"></textarea>
                                <p class="text-muted small">
                                    @if($settings['has_secrets']['google_drive:service_account_json'] ?? false)
                                        Currently configured. Leave blank to keep existing, use <code>!clear</code> to remove.
                                    @else
                                        Paste the full Google service account JSON key. Leave blank to keep empty.
                                    @endif
                                </p>
                                <p class="text-muted small">
                                    Share your destination folder with the service account email from that JSON file.
                                </p>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Client Secret <span class="field-optional"></span></label>
                                <input type="password" class="form-control" name="auto_backups:google_drive:client_secret" />
                                <p class="text-muted small">
                                    @if($settings['has_secrets']['google_drive:client_secret'])
                                        Currently configured. Leave blank to keep existing, use <code>!clear</code> to remove.
                                    @else
                                        Leave blank to keep empty.
                                    @endif
                                </p>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Refresh Token <span class="field-optional"></span></label>
                                <input type="password" class="form-control" name="auto_backups:google_drive:refresh_token" />
                                <p class="text-muted small">
                                    @if($settings['has_secrets']['google_drive:refresh_token'])
                                        Currently configured. Leave blank to keep existing, use <code>!clear</code> to remove.
                                    @else
                                        Leave blank to keep empty.
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">S3 Defaults</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-4">
                                <label class="control-label">Bucket</label>
                                <input type="text" class="form-control" name="auto_backups:s3:bucket" value="{{ old('auto_backups:s3:bucket', $settings['destinations']['s3']['bucket']) }}" />
                            </div>
                            <div class="form-group col-md-4">
                                <label class="control-label">Region</label>
                                <input type="text" class="form-control" name="auto_backups:s3:region" value="{{ old('auto_backups:s3:region', $settings['destinations']['s3']['region']) }}" />
                            </div>
                            <div class="form-group col-md-4">
                                <label class="control-label">Access Key ID</label>
                                <input type="text" class="form-control" name="auto_backups:s3:access_key_id" value="{{ old('auto_backups:s3:access_key_id', $settings['destinations']['s3']['access_key_id']) }}" />
                            </div>
                            <div class="form-group col-md-4">
                                <label class="control-label">Endpoint <span class="field-optional"></span></label>
                                <input type="text" class="form-control" name="auto_backups:s3:endpoint" value="{{ old('auto_backups:s3:endpoint', $settings['destinations']['s3']['endpoint']) }}" />
                            </div>
                            <div class="form-group col-md-4">
                                <label class="control-label">Path Prefix <span class="field-optional"></span></label>
                                <input type="text" class="form-control" name="auto_backups:s3:path_prefix" value="{{ old('auto_backups:s3:path_prefix', $settings['destinations']['s3']['path_prefix']) }}" />
                            </div>
                            <div class="form-group col-md-4">
                                <label class="control-label">Use Path Style Endpoint</label>
                                <div>
                                    <input type="hidden" name="auto_backups:s3:use_path_style" value="0" />
                                    <input type="checkbox" name="auto_backups:s3:use_path_style" value="1" @if(old('auto_backups:s3:use_path_style', $settings['destinations']['s3']['use_path_style'] ? '1' : '0') === '1') checked @endif />
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Secret Access Key <span class="field-optional"></span></label>
                                <input type="password" class="form-control" name="auto_backups:s3:secret_access_key" />
                                <p class="text-muted small">
                                    @if($settings['has_secrets']['s3:secret_access_key'])
                                        Currently configured. Leave blank to keep existing, use <code>!clear</code> to remove.
                                    @else
                                        Leave blank to keep empty.
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Dropbox Defaults</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-6">
                                <label class="control-label">Folder Path <span class="field-optional"></span></label>
                                <input type="text" class="form-control" name="auto_backups:dropbox:folder_path" value="{{ old('auto_backups:dropbox:folder_path', $settings['destinations']['dropbox']['folder_path']) }}" />
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Access Token <span class="field-optional"></span></label>
                                <input type="password" class="form-control" name="auto_backups:dropbox:access_token" />
                                <p class="text-muted small">
                                    @if($settings['has_secrets']['dropbox:access_token'])
                                        Currently configured. Leave blank to keep existing, use <code>!clear</code> to remove.
                                    @else
                                        Leave blank to keep empty.
                                    @endif
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="box-footer">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn btn-sm btn-primary pull-right">Save</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection
