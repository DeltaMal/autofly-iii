{{--
    ext fork: the custom period view range, included by one @includeIf line inside upstream's
    view-range radio group (resources/views/preferences/index.blade.php). Saved by
    FireflyIII\Ext\Http\Middleware\SaveExtPeriodPreference.
--}}
@php($extPeriod = \FireflyIII\Ext\Periods\ExtNavigation::settings())
<div class="radio">
    <label>
        <input type="radio" name="viewRange" value="CP" @if(($viewRange ?? '') === 'CP') checked @endif>
        {{ __('ext::periods.pref_CP') }}
    </label>
</div>
<div class="ms-4 mb-2">
    <div class="row g-2">
        <div class="col-md-4">
            <label class="form-label small" for="ext_period_anchor_day">{{ __('ext::periods.anchor_day') }}</label>
            <select class="form-select form-select-sm" id="ext_period_anchor_day" name="ext_period[anchor_day]">
                <option value="" @if(null === $extPeriod->anchorDay) selected @endif>{{ __('ext::periods.anchor_manual') }}</option>
                @for($day = 1; $day <= 31; $day++)
                    <option value="{{ $day }}" @if($extPeriod->anchorDay === $day) selected @endif>{{ 31 === $day ? __('ext::periods.anchor_last_day') : $day }}</option>
                @endfor
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small" for="ext_period_rule">{{ __('ext::periods.business_day_rule') }}</label>
            <select class="form-select form-select-sm" id="ext_period_rule" name="ext_period[business_day_rule]">
                @foreach(\FireflyIII\Ext\Periods\PeriodSettings::BUSINESS_DAY_RULES as $rule)
                    <option value="{{ $rule }}" @if($extPeriod->businessDayRule === $rule) selected @endif>{{ __('ext::periods.rule_'.$rule) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small" for="ext_period_label">{{ __('ext::periods.label') }}</label>
            <select class="form-select form-select-sm" id="ext_period_label" name="ext_period[label]">
                @foreach(\FireflyIII\Ext\Periods\PeriodSettings::LABEL_STYLES as $style)
                    <option value="{{ $style }}" @if($extPeriod->label === $style) selected @endif>{{ __('ext::periods.label_'.$style) }}</option>
                @endforeach
            </select>
        </div>
    </div>
    @if(Route::has('ext.periods.index'))
        <p class="small mt-1 mb-0"><a href="{{ route('ext.periods.index') }}">{{ __('ext::periods.close_period') }} …</a></p>
    @endif
</div>
