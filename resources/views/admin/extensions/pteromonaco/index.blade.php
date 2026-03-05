@extends('layouts.admin')
<?php 
    // Define extension information.
    $EXTENSION_ID = "pteromonaco";
    $EXTENSION_NAME = stripslashes("PteroMonaco");
    $EXTENSION_VERSION = "1.2";
    $EXTENSION_DESCRIPTION = stripslashes("Replaces the regular pterodactyl file editor with Monaco");
    $EXTENSION_ICON = "/assets/extensions/pteromonaco/icon.png";
    $EXTENSION_WEBSITE = "https://zephrynis.me/";
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
    @yield('extension.description')<!-- 
  Content on this page will be displayed on your extension's
  admin page.
-->
@endsection
