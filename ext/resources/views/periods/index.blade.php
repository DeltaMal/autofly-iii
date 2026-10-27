@extends('ext::layout.page')
@section('content')
    @if(!$active)
        <div class="alert alert-info">{{ __('ext::periods.not_active') }}
            <a href="{{ route('preferences.index') }}">{{ __('ext::periods.settings_link') }}</a></div>
    @endif
    <div class="row">
        <div class="col-lg-7 col-md-12">
            <div class="card mb-3">
                <div class="card-header">
                    <h3 class="card-title">{{ __('ext::periods.current_and_next') }}</h3>
                    <div class="card-tools small text-muted">
                        @if(null === $settings->anchorDay)
                            {{ __('ext::periods.mode_manual') }}
                        @else
                            {{ __('ext::periods.mode_anchored', ['day' => 31 === $settings->anchorDay ? __('ext::periods.anchor_last_day') : $settings->anchorDay]) }}
                            ({{ __('ext::periods.rule_'.$settings->businessDayRule) }})
                        @endif
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                        <tr>
                            <th>{{ __('ext::periods.period') }}</th>
                            <th>{{ __('ext::periods.start') }}</th>
                            <th>{{ __('ext::periods.end') }}</th>
                            <th class="text-end">{{ __('ext::periods.days') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($periods as $index => $period)
                            <tr @if(0 === $index) class="table-active fw-bold" @endif>
                                <td>{{ $period['label'] }}</td>
                                <td>{{ $period['start']->isoFormat($monthAndDayFormat) }} {{ $period['start']->year }}</td>
                                <td>{{ $period['end']->isoFormat($monthAndDayFormat) }} {{ $period['end']->year }}</td>
                                <td class="text-end">{{ $period['days'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-5 col-md-12">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">{{ __('ext::periods.close_period') }}</h3></div>
                <form method="post" action="{{ route('ext.periods.close') }}">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                    <div class="card-body">
                        <p class="small">{{ __('ext::periods.close_period_help') }}</p>
                        <div class="mb-2">
                            <label class="form-label" for="ext_close_date">{{ __('ext::periods.date') }}</label>
                            <input class="form-control" type="date" id="ext_close_date" name="date" value="{{ old('date', $today) }}" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="ext_close_name">{{ __('ext::periods.optional_name') }}</label>
                            <input class="form-control" type="text" id="ext_close_name" name="name" maxlength="100" value="{{ old('name') }}">
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary"><em class="bi bi-calendar-check"></em> {{ __('ext::periods.close_period') }}</button>
                    </div>
                </form>
            </div>
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">{{ __('ext::periods.explicit_starts') }}</h3></div>
                <div class="card-body p-0">
                    @if(0 === count($starts))
                        <p class="p-3 mb-0 text-muted">{{ __('ext::periods.no_explicit_starts') }}</p>
                    @else
                        <table class="table table-sm mb-0">
                            <thead><tr><th>{{ __('ext::periods.date') }}</th><th>{{ __('ext::periods.name') }}</th><th></th></tr></thead>
                            <tbody>
                            @foreach($starts as $start)
                                <tr>
                                    <td>{{ $start['date'] }}</td>
                                    <td>{{ $start['name'] ?? '' }}</td>
                                    <td class="text-end">
                                        <form method="post" action="{{ route('ext.periods.starts.delete') }}" class="d-inline">
                                            <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                                            <input type="hidden" name="date" value="{{ $start['date'] }}"/>
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><em class="bi bi-trash"></em> {{ __('ext::periods.delete') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
