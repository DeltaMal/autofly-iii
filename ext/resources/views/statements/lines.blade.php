{{-- One table of statement lines. $select: name of the checkbox array (create|apply) or null. --}}
@if(0 === count($lines))
    <p class="text-muted mb-0">{{ __('ext::statements.nothing') }}</p>
@else
    <table class="table table-sm mb-0">
        <thead>
        <tr>
            @if(null !== $select)<th>{{ __('ext::statements.'.$select) }}</th><th>{{ __('ext::statements.ignore') }}</th>@endif
            <th>{{ __('ext::statements.date') }}</th>
            <th>{{ __('ext::statements.description') }}</th>
            <th class="text-end">{{ __('ext::statements.amount') }}</th>
            <th class="text-end">{{ __('ext::statements.balance') }}</th>
            <th>{{ __('ext::statements.transaction') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach($lines as $line)
            <tr>
                @if(null !== $select)
                    <td><input class="form-check-input" type="checkbox" name="{{ $select }}[]" value="{{ $line->id }}" @if($checked) checked @endif aria-label="{{ __('ext::statements.'.$select) }}"></td>
                    <td><input class="form-check-input" type="checkbox" name="ignore[]" value="{{ $line->id }}" aria-label="{{ __('ext::statements.ignore') }}"></td>
                @endif
                <td>{{ $line->date->format('Y-m-d') }}</td>
                <td>{{ $line->description }}@if($line->card_ref) <small class="text-muted">card ..{{ $line->card_ref }}</small>@endif</td>
                <td class="text-end">{{ $line->amount }}</td>
                <td class="text-end">{{ $line->balance }}</td>
                <td>
                    @if(null !== $line->transaction_journal_id && $line->journal)
                        <a href="{{ route('transactions.show', [$line->journal->transaction_group_id]) }}">{{ $line->journal->description }}</a>
                        <small class="text-muted">{{ __('ext::statements.status_line_'.$line->status, []) }}@if(null !== $line->match_score) · {{ __('ext::statements.score') }} {{ round((float) $line->match_score, 1) }}@endif</small>
                    @endif
                    @if(is_array($line->proposal))
                        <div class="small">{{ __('ext::statements.proposal_text', $line->proposal) }}
                            @php($percent = \FireflyIII\Ext\Statements\Reconciler::percentDifference((string) $line->proposal['from'], (string) $line->proposal['to']))
                            @if(null !== $percent)<span class="badge text-bg-light ext-proposal-percent">{{ __('ext::statements.proposal_percent', ['percent' => $percent]) }}</span>@endif
                        </div>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
