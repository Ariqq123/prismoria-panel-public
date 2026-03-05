@extends('layouts.admin')
<?php 
    // Define extension information.
    $EXTENSION_ID = "subdomainmanager";
    $EXTENSION_NAME = stripslashes("SubdomainManager");
    $EXTENSION_VERSION = "1.0";
    $EXTENSION_DESCRIPTION = stripslashes("Manage subdomains with cloudflare SRV records.");
    $EXTENSION_ICON = "/assets/extensions/subdomainmanager/icon.jpg";
    $EXTENSION_WEBSITE = "[website]";
    $EXTENSION_WEBICON = "[webicon]";
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
    @yield('extension.description')@extends('layouts.admin')

@section('title')
    List Domains
@endsection

@section('content-header')
    <h1>SubDomain Manager<small>You can create, edit, delete domains.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">SubDomain Manager</li>
    </ol>
@endsection

@section('content')
    <div class="row">
        <div class="col-md-8 col-xs-12">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Domain List</h3>
                    <div class="box-tools">
                        <a href="{{ route('admin.subdomain.new') }}"><button type="button" class="btn btn-sm btn-primary" style="border-radius: 0 3px 3px 0;margin-left:-1px;">Create New</button></a>
                    </div>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <tbody>
                        <tr>
                            <th>#</th>
                            <th>Domain</th>
                            <th>Provider</th>
                            <th>Actions</th>
                        </tr>
                        @foreach ($domains as $domain)
                            <tr>
                                <td>{{$domain['id']}}</td>
                                <td>{{$domain['domain']}}</td>
                                <td>{{ strtoupper($domain['provider'] ?? 'cloudflare') }}</td>
                                <td>
                                    <a title="Edit" class="btn btn-xs btn-primary" href="{{ route('admin.subdomain.edit', $domain['id']) }}"><i class="fa fa-pencil"></i></a>
                                    <a title="Delete" class="btn btn-xs btn-danger" data-action="delete" data-id="{{ $domain['id'] }}"><i class="fa fa-trash"></i></a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="box box-success">
                <div class="box-header with-border">
                    <h3 class="box-title">SubDomain List</h3>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <tbody>
                        <tr>
                            <th>#</th>
                            <th>SubDomain</th>
                            <th>Server</th>
                            <th>Actions</th>
                        </tr>
                        @foreach ($subdomains as $subdomain)
                            <tr>
                                <td>{{$subdomain['id']}}</td>
                                <td>{{$subdomain['subdomain']}}.{{$subdomain['domain']['domain']}}</td>
                                <td><a href="{{ route('index') }}/server/{{ $subdomain['server']->uuidShort }}" target="_blank">{{$subdomain['server']->name}}</a></td>
                                <td>
                                    <a title="View" class="btn btn-xs btn-primary" href="/server/{{ $subdomain['server']->uuidShort }}" target="_blank"><i class="fa fa-eye"></i></a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-4 col-xs-12">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Settings</h3>
                </div>
                <form method="post" action="{{ route('admin.subdomain.settings')  }}">
                    <div class="box-body">
                        <div class="form-group">
                            <label for="cf_api_token" class="form-label">Cloudflare API Token (Recommended)</label>
                            <input type="text" name="cf_api_token" id="cf_api_token" class="form-control" value="{{ $settings['cf_api_token'] ?? '' }}" />
                            <p class="small text-muted no-margin">Use a scoped token with Zone Read + DNS Write permissions.</p>
                        </div>
                        <div class="form-group">
                            <label for="cf_email" class="form-label">Cloudflare Email (Legacy)</label>
                            <input type="text" name="cf_email" id="cf_email" class="form-control" value="{{ $settings['cf_email'] }}" />
                        </div>
                        <div class="form-group">
                            <label for="cf_api_key" class="form-label">Cloudflare Global API Key (Legacy)</label>
                            <input type="text" name="cf_api_key" id="cf_api_key" class="form-control" value="{{ $settings['cf_api_key'] }}" />
                        </div>
                        <hr>
                        <div class="form-group">
                            <label for="min3_api_key" class="form-label">Min3 API Key</label>
                            <input type="text" name="min3_api_key" id="min3_api_key" class="form-control" value="{{ $settings['min3_api_key'] ?? '' }}" />
                            <p class="small text-muted no-margin">Used for domains configured with provider <code>min3</code>.</p>
                        </div>
                        <div class="form-group">
                            <label for="max_subdomain" class="form-label">Max subdomain per server</label>
                            <input type="text" name="max_subdomain" id="max_subdomain" class="form-control" value="{{ $settings['max_subdomain'] }}" />
                        </div>
                    </div>
                    <div class="box-footer">
                        {!! csrf_field() !!}
                        <button type="submit" class="btn btn-success pull-right">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@section('footer-scripts')
    @parent
    <script>
        $('[data-action="delete"]').click(function (event) {
            event.preventDefault();
            let self = $(this);
            swal({
                title: '',
                type: 'warning',
                text: 'Are you sure you want to delete this domain?',
                showCancelButton: true,
                confirmButtonText: 'Delete',
                confirmButtonColor: '#d9534f',
                closeOnConfirm: false,
                showLoaderOnConfirm: true,
                cancelButtonText: 'Cancel',
            }, function () {
                $.ajax({
                    method: 'DELETE',
                    url: '{{ route('admin.subdomain.delete') }}',
                    headers: {'X-CSRF-TOKEN': $('meta[name="_token"]').attr('content')},
                    data: {
                        id: self.data('id')
                    }
                }).done((data) => {
                    if (data.success === true) {
                        swal({
                            type: 'success',
                            title: 'Success!',
                            text: 'You have successfully deleted this domain.'
                        });

                        self.parent().parent().slideUp();
                    } else {
                        swal({
                            type: 'error',
                            title: 'Ooops!',
                            text: (typeof data.error !== 'undefined') ? data.error : 'Failed to delete this domain! Please try again later...'
                        });
                    }
                }).fail(() => {
                    swal({
                        type: 'error',
                        title: 'Ooops!',
                        text: 'A system error has occurred! Please try again later...'
                    });
                });
            });
        });
    </script>
@endsection
@endsection
