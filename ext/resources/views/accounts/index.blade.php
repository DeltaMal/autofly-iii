@extends('ext::layout.page')
@section('content')
    <div class="row">
        <div class="col-lg-8 col-md-12">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">{{ __('ext::mail.accounts') }}</h3></div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>{{ __('ext::mail.account') }}</th><th>{{ __('ext::mail.auto_number') }}</th><th>{{ __('ext::mail.identifiers') }}</th></tr></thead>
                        <tbody>
                        @foreach($accounts as $id => $account)
                            <tr>
                                <td><a href="{{ route('accounts.show', [$id]) }}">{{ $account['name'] }}</a> <small class="text-muted">{{ $account['type'] }}</small></td>
                                <td><code>{{ $account['number'] }}</code></td>
                                <td>
                                    @foreach($identifiers->get($id, collect()) as $identifier)
                                        <form class="d-inline" method="post" action="{{ route('ext.accounts.identifiers.destroy', [$identifier->id]) }}">
                                            <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                                            <span class="badge text-bg-info">{{ strtoupper($identifier->bank) }} {{ __('ext::mail.kind_'.$identifier->kind) }} {{ $identifier->value }}</span>
                                            <button class="btn btn-link btn-sm p-0 text-danger" type="submit" title="{{ __('ext::mail.delete') }}"><em class="bi bi-x"></em></button>
                                        </form>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-12">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">{{ __('ext::mail.add_identifier') }}</h3></div>
                <form method="post" action="{{ route('ext.accounts.identifiers.store') }}">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                    <div class="card-body">
                        <div class="mb-2"><label class="form-label" for="ext_id_account">{{ __('ext::mail.account') }}</label>
                            <select class="form-select" id="ext_id_account" name="account_id">@foreach($accounts as $id => $account)<option value="{{ $id }}">{{ $account['name'] }}</option>@endforeach</select></div>
                        <div class="mb-2"><label class="form-label" for="ext_id_bank">{{ __('ext::mail.bank') }}</label>
                            <select class="form-select" id="ext_id_bank" name="bank">@foreach($banks as $bid => $bank)<option value="{{ $bid }}">{{ $bank->name() }}</option>@endforeach</select></div>
                        <div class="mb-2"><label class="form-label" for="ext_id_kind">{{ __('ext::mail.identifiers') }}</label>
                            <select class="form-select" id="ext_id_kind" name="kind"><option value="account_number">{{ __('ext::mail.kind_account_number') }}</option><option value="card">{{ __('ext::mail.kind_card') }}</option></select></div>
                        <div class="mb-2"><label class="form-label" for="ext_id_value">{{ __('ext::mail.value') }}</label>
                            <input class="form-control @error('value') is-invalid @enderror" id="ext_id_value" name="value" inputmode="numeric" required>@error('value')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    </div>
                    <div class="card-footer"><button class="btn btn-success" type="submit">{{ __('ext::mail.save') }}</button></div>
                </form>
            </div>
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">{{ __('ext::mail.test_match') }}</h3></div>
                <form method="get" action="{{ route('ext.accounts.index') }}">
                    <div class="card-body">
                        <div class="input-group">
                            <select class="form-select" name="bank" aria-label="{{ __('ext::mail.bank') }}">@foreach($banks as $bid => $bank)<option value="{{ $bid }}" @if($testBank === $bid) selected @endif>{{ $bank->name() }}</option>@endforeach</select>
                            <input class="form-control" name="test" value="{{ $test }}" inputmode="numeric" aria-label="{{ __('ext::mail.value') }}">
                            <button class="btn btn-outline-primary" type="submit">{{ __('ext::mail.test') }}</button>
                        </div>
                        @if(null !== $resolution)
                            <p class="mt-2 mb-0 @if($resolution->resolved()) text-success @else text-danger @endif">
                                @if($resolution->resolved())
                                    {{ __('ext::mail.test_result_ok', ['value' => $test, 'account' => $accounts[$resolution->accountId]['name'] ?? '#'.$resolution->accountId, 'via' => $resolution->via]) }}
                                @else
                                    {{ __('ext::mail.test_result_fail', ['value' => $test, 'problem' => $resolution->problem]) }}
                                @endif
                            </p>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
