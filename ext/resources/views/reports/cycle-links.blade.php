{{--
    ext fork: custom period quick links on the reports page, included by one @includeIf line after upstream's
    preset ranges (resources/views/reports/index.blade.php). Each link opens upstream's default report for
    one cycle. Renders nothing unless the view range is CP.
--}}
@php($extCycles = \FireflyIII\Ext\Periods\ReportCycleLinks::grouped($start ?? today(config('app.timezone'))))
@if([] !== $extCycles && '' !== ($accountList ?? ''))
    <div class="row mb-2" id="ext-cycle-links">
        <label for="__none__" class="col-sm-3 col-form-label">{{ __('ext::periods.report_cycles') }}</label>
        <div class="col-sm-9">
            @foreach($extCycles as $year => $cycles)
                {{ $year }}
                <ul class="list-inline">
                    @foreach($cycles as $cycle)
                        <li class="list-inline-item">
                            <a href="{{ route('reports.report.default', [$accountList, $cycle['start'], $cycle['end']]) }}">{{ $cycle['label'] }}</a>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </div>
    </div>
@endif
