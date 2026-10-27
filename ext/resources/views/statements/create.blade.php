@extends('ext::layout.page')
@section('content')
    <form method="post" action="{{ route('ext.statements.store') }}" enctype="multipart/form-data" autocomplete="off">
        <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
        <div class="row">
            <div class="col-lg-6 col-md-12">
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">{{ __('ext::statements.upload') }}</h3></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="ext_account">{{ __('ext::statements.account') }}</label>
                            <select class="form-select @error('account_id') is-invalid @enderror" id="ext_account" name="account_id">
                                <option value="">{{ __('ext::statements.account_auto') }}</option>
                                @foreach($accounts as $id => $name)
                                    <option value="{{ $id }}" @if((int) old('account_id') === $id) selected @endif>{{ $name }}</option>
                                @endforeach
                            </select>
                            @error('account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">{{ __('ext::statements.account_help') }}</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="ext_file">{{ __('ext::statements.file') }}</label>
                            <input class="form-control @error('file') is-invalid @enderror" type="file" id="ext_file" name="file" accept="application/pdf,.pdf" required>
                            @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">{{ __('ext::statements.file_help', ['kb' => $maxKb]) }}</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="ext_password">{{ __('ext::statements.password') }}</label>
                            <input class="form-control" type="password" id="ext_password" name="password" autocomplete="new-password">
                            <div class="form-text">{{ __('ext::statements.password_help') }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6 col-md-12">
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">{{ __('ext::statements.options') }}</h3></div>
                    <div class="card-body">
                        @foreach($defaults as $option => $default)
                            <div class="form-check">
                                <input type="hidden" name="options[{{ $option }}]" value="0">
                                <input class="form-check-input" type="checkbox" id="ext_opt_{{ $option }}" name="options[{{ $option }}]" value="1" @if(old('options.'.$option, $default ? '1' : '0') === '1' || (null === old('options') && $default)) checked @endif>
                                <label class="form-check-label" for="ext_opt_{{ $option }}">{{ __('ext::statements.opt_'.$option) }}</label>
                            </div>
                        @endforeach
                        <hr>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="ext_reprocess" name="reprocess" value="1">
                            <label class="form-check-label" for="ext_reprocess">{{ __('ext::statements.reprocess') }}</label>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-success"><em class="bi bi-upload"></em> {{ __('ext::statements.upload') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
