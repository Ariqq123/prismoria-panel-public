@extends('layouts.admin')
<?php 
    // Define extension information.
    $EXTENSION_ID = "votifiertester";
    $EXTENSION_NAME = stripslashes("Votifier Tester");
    $EXTENSION_VERSION = "1.0";
    $EXTENSION_DESCRIPTION = stripslashes("This form allows you to send test votes to a server using the Votifier plugin.");
    $EXTENSION_ICON = "/assets/extensions/votifiertester/icon.png";
    $EXTENSION_WEBSITE = "https://www.sourcexchange.net/products/votifier-tester-for-pterodactyl";
    $EXTENSION_WEBICON = "bi bi-tag-fill";
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
    @yield('extension.description')<div class="box box-info">
  <div class="box-header with-border">
    <h3 class="box-title">Information</h3>
  </div>
  <div class="box-body">
    <p>
      This extension is called <b>Votifier Tester</b>. <br>
      <code>votifiertester</code> is the identifier of this extension. <br>
      The current version is <i>1.0</i>. <br>
    </p>
  </div>
</div>
@endsection
