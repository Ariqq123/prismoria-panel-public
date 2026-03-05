@extends('layouts.admin')

@section('title')
    Player Manager Extension
@endsection

@section('content-header')
    <h1>Player Manager<small>Blueprint wiring entry</small></h1>
@endsection

@section('content')
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-body">
                    <p>
                        Player Manager is wired to Blueprint for extension registry and routing compatibility.
                    </p>
                    <p class="text-muted">
                        This module is configured from the client server panel directly (not from a global admin page).
                    </p>
                    <a class="btn btn-primary btn-sm" href="{{ route('admin.extensions') }}">
                        Back to Extensions
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
