@extends('ext::layout.page')
@section('content')
    <div class="row">
        @foreach($banks as $id => $bank)
            @php($profile = $profiles[$id])
            <div class="col-lg-6 col-md-12">
                <form method="post" action="{{ route('ext.banks.update', [$id]) }}">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title">{{ $bank->name() }} <small class="text-muted">({{ $bank->country() }})</small></h3></div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label" for="ext_match_{{ $id }}">{{ __('ext::mail.account_match') }}</label>
                                <select class="form-select" id="ext_match_{{ $id }}" name="account_match">
                                    @foreach(['prefix', 'suffix'] as $match)<option value="{{ $match }}" @if($profile->account_match === $match) selected @endif>{{ __('ext::mail.match_'.$match) }}</option>@endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="ext_default_{{ $id }}">{{ __('ext::mail.default_account') }}</label>
                                <select class="form-select" id="ext_default_{{ $id }}" name="default_account_id">
                                    <option value="">{{ __('ext::mail.none') }}</option>
                                    @foreach($accounts as $accountId => $name)<option value="{{ $accountId }}" @if($profile->default_account_id === $accountId) selected @endif>{{ $name }}</option>@endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="ext_tags_{{ $id }}">{{ __('ext::mail.tags') }}</label>
                                <input class="form-control" id="ext_tags_{{ $id }}" name="tags" value="{{ implode(', ', (array) $profile->tags) }}">
                                <div class="form-text">{{ __('ext::mail.tags_help') }}</div>
                            </div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="ext_auth_{{ $id }}" name="require_authenticated" value="1" @if($profile->require_authenticated) checked @endif><label class="form-check-label" for="ext_auth_{{ $id }}">{{ __('ext::mail.require_auth') }}</label></div>
                            <div class="form-check"><input class="form-check-input" type="checkbox" id="ext_enabled_{{ $id }}" name="enabled" value="1" @if($profile->enabled) checked @endif><label class="form-check-label" for="ext_enabled_{{ $id }}">{{ __('ext::mail.enabled') }}</label></div>
                        </div>
                        <div class="card-footer"><button class="btn btn-success" type="submit">{{ __('ext::mail.save') }}</button></div>
                    </div>
                </form>
            </div>
        @endforeach
    </div>
@endsection
