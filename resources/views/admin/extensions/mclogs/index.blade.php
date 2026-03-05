@extends('layouts.admin')
<?php 
    // Define extension information.
    $EXTENSION_ID = "mclogs";
    $EXTENSION_NAME = stripslashes("MC Logs");
    $EXTENSION_VERSION = "1.6";
    $EXTENSION_DESCRIPTION = stripslashes("Addon that allows users to Utilise the MCLOGS API");
    $EXTENSION_ICON = "/assets/extensions/mclogs/icon.jpg";
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
    @yield('extension.description'){{-- Blueprint admin view for the addon.
     `MC Logs`, `Euphoria Development`, and `mclogs` are placeholders populated by Blueprint from `conf.yml`. --}}
<div class="row">
    <div class="col-xs-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title"><strong>MC Logs</strong> by <strong>Euphoria Development</strong></h3>
            </div>
            <div class="box-body">
                Identifier: <code>mclogs</code><br>
                Uninstall using: <code>blueprint -remove mclogs</code><br>
                Get support via <a href="https://discord.gg/Cus2zP4pPH" target="_blank" rel="noopener noreferrer">Discord</a><br>
            </div>
        </div>
    </div>
</div>
@endsection
