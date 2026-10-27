@extends('ext::layout.page')
@section('content')
    <div class="row">
        <div class="col-lg-8 col-md-12">
            <form method="post" action="{{ route('ext.admin.google.update') }}" autocomplete="off">
                <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">{{ __('ext::mail.google') }}</h3></div>
                    <div class="card-body">
                        <p>{{ __('ext::mail.google_help') }}</p>
                        <div class="alert alert-warning">{{ __('ext::mail.google_consent') }}</div>
                        <div class="mb-3">
                            <label class="form-label" for="ext_redirect">{{ __('ext::mail.redirect_uri') }}</label>
                            <input class="form-control" id="ext_redirect" value="{{ $redirectUri }}" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="ext_client_id">{{ __('ext::mail.client_id') }}</label>
                            <input class="form-control @error('client_id') is-invalid @enderror" id="ext_client_id" name="client_id" value="{{ old('client_id', $clientId) }}" required>
                            @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="ext_client_secret">{{ __('ext::mail.client_secret') }}</label>
                            <input class="form-control" type="password" id="ext_client_secret" name="client_secret" placeholder="@if($hasSecret)••••••••@endif" autocomplete="new-password">
                            <div class="form-text">{{ __('ext::mail.client_secret_help') }}</div>
                        </div>
                    </div>
                    <div class="card-footer"><button class="btn btn-success" type="submit">{{ __('ext::mail.save') }}</button></div>
                </div>
            </form>
        </div>
    </div>
@endsection
