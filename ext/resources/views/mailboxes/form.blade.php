@extends('ext::layout.page')
@section('content')
    <form method="post" action="{{ $mailbox->exists ? route('ext.mailboxes.update', [$mailbox->id]) : route('ext.mailboxes.store') }}">
        <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">{{ $mailbox->exists ? $mailbox->name : __('ext::mail.new_mailbox') }}</h3></div>
            <div class="card-body">
                @foreach(['name' => 'text', 'query' => 'text', 'lookback_days' => 'number', 'label_imported' => 'text', 'label_unmatched' => 'text'] as $field => $type)
                    <div class="mb-3">
                        <label class="form-label" for="ext_{{ $field }}">{{ __('ext::mail.'.$field) }}</label>
                        <input class="form-control @error($field) is-invalid @enderror" type="{{ $type }}" id="ext_{{ $field }}" name="{{ $field }}" value="{{ old($field, $mailbox->{$field}) }}" @if('query' !== $field) required @endif>
                        @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                        @if(in_array($field, ['name', 'query'], true))<div class="form-text">{{ __('ext::mail.'.$field.'_help') }}</div>@endif
                    </div>
                @endforeach
                <div class="mb-3">
                    <span class="form-label d-block">{{ __('ext::mail.banks') }}</span>
                    @foreach($banks as $id => $bank)
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="ext_bank_{{ $id }}" name="banks[]" value="{{ $id }}" @if(in_array($id, old('banks', (array) $mailbox->banks), true)) checked @endif>
                            <label class="form-check-label" for="ext_bank_{{ $id }}">{{ $bank->name() }}</label>
                        </div>
                    @endforeach
                    @error('banks')<div class="text-danger small">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label" for="ext_mode">{{ __('ext::mail.mode') }}</label>
                    <select class="form-select" id="ext_mode" name="mode">
                        @foreach([\FireflyIII\Ext\Models\Mailbox::SHADOW, \FireflyIII\Ext\Models\Mailbox::LIVE] as $mode)
                            <option value="{{ $mode }}" @if(old('mode', $mailbox->mode) === $mode) selected @endif>{{ __('ext::mail.mode_'.$mode) }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">{{ __('ext::mail.mode_help') }}</div>
                </div>
                <div class="form-check">
                    <input type="hidden" name="enabled" value="0">
                    <input class="form-check-input" type="checkbox" id="ext_enabled" name="enabled" value="1" @if(old('enabled', $mailbox->enabled)) checked @endif>
                    <label class="form-check-label" for="ext_enabled">{{ __('ext::mail.enabled') }}</label>
                </div>
            </div>
            <div class="card-footer"><button type="submit" class="btn btn-success">{{ __('ext::mail.save') }}</button></div>
        </div>
    </form>
    @if($mailbox->exists)
        <form method="post" action="{{ route('ext.mailboxes.destroy', [$mailbox->id]) }}">
            <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
            <button type="submit" class="btn btn-outline-danger btn-sm"><em class="bi bi-trash"></em> {{ __('ext::mail.delete') }}</button>
        </form>
    @endif
@endsection
