{{-- Base layout for ext pages: upstream's session layout plus simple breadcrumbs. --}}
@extends('layout.v3.session')
@section('breadcrumbs')
    <ol class="breadcrumb float-sm-end">
        <li class="breadcrumb-item"><a href="{{ route('index') }}">{{ __('firefly.home') }}</a></li>
        @foreach(($extBreadcrumbs ?? []) as $label => $url)
            @if($loop->last)
                <li class="breadcrumb-item active" aria-current="page">{{ $label }}</li>
            @else
                <li class="breadcrumb-item"><a href="{{ $url }}">{{ $label }}</a></li>
            @endif
        @endforeach
    </ol>
@endsection
@section('scripts')
    @vite(['js/pages/generic.js'])
    @yield('ext-scripts')
@endsection
