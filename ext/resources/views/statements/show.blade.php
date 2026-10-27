@extends('ext::layout.page')
@section('content')
    @php($committed = \FireflyIII\Ext\Models\Statement::STATUS_COMMITTED === $statement->status)
    @if(!$statement->chain_valid)
        <div class="alert alert-danger">{{ __('ext::statements.chain_invalid') }}</div>
    @endif
    <div class="row">
        <div class="col-lg-6 col-md-12">
            <div class="card mb-3">
                <div class="card-header">
                    <h3 class="card-title">{{ __('ext::statements.breakdown') }}</h3>
                    <div class="card-tools small">{{ $statement->account?->name }} · {{ $statement->period_start->format('Y-m-d') }} – {{ $statement->statement_date->format('Y-m-d') }}</div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tbody>
                        @foreach(['closing_balance' => 'b_closing', 'firefly_balance' => 'b_firefly', 'pending' => 'b_pending', 'missing' => 'b_missing', 'proposals' => 'b_proposals', 'expected_balance' => 'b_expected'] as $key => $label)
                            <tr><td>{{ __('ext::statements.'.$label) }}</td><td class="text-end">{{ $breakdown[$key] ?? '' }}</td></tr>
                        @endforeach
                        <tr class="fw-bold @if('0.00' !== ($breakdown['unexplained'] ?? '0.00')) table-warning @else table-success @endif">
                            <td>{{ __('ext::statements.b_unexplained') }}</td><td class="text-end">{{ $breakdown['unexplained'] ?? '' }}</td>
                        </tr>
                        @if('' !== ($breakdown['available_balance'] ?? ''))
                            <tr class="text-muted"><td>{{ __('ext::statements.b_available') }}</td><td class="text-end">{{ $breakdown['available_balance'] }}</td></tr>
                        @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @if($committed)
            <div class="col-lg-6 col-md-12">
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">{{ __('ext::statements.result') }}</h3></div>
                    <div class="card-body">
                        <ul class="mb-2">
                            <li>{{ __('ext::statements.result_created', ['count' => count((array) ($statement->summary['created_journals'] ?? []))]) }}</li>
                            <li>{{ __('ext::statements.result_reconciled', ['count' => (int) ($statement->summary['reconciled'] ?? 0)]) }}</li>
                            @if(null !== ($statement->summary['adjustment_group'] ?? null))
                                <li><a href="{{ route('transactions.show', [$statement->summary['adjustment_group']]) }}">{{ __('ext::statements.unexplained_was_booked') }}: {{ $statement->summary['unexplained']['amount'] ?? '' }}</a></li>
                            @endif
                        </ul>
                        @if(null !== $importTag)
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('tags.show', [$importTag->id]) }}">{{ __('ext::statements.view_created') }}</a>
                        @endif
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('accounts.reconcile', [$statement->account_id, $statement->period_start->format('Y-m-d'), $statement->statement_date->format('Y-m-d')]) }}">{{ __('ext::statements.view_reconcile') }}</a>
                    </div>
                </div>
            </div>
        @endif
    </div>

    @php($remaining = $unexplained['remaining'])
    @php($hasRemaining = 0 !== bccomp($remaining, '0', 2))
    <div class="card mb-3" id="ext-unexplained">
        <div class="card-header">
            <h3 class="card-title">{{ __('ext::statements.unexplained') }}</h3>
        </div>
        <div class="card-body p-0">
            <p class="small text-muted px-3 pt-2 mb-2">{{ __('ext::statements.unexplained_help') }}</p>
            <table class="table table-sm mb-0">
                <tbody>
                @foreach($unexplained['items'] as $item)
                    <tr>
                        <td>{{ __('ext::statements.unexplained_kind_'.$item['kind']) }}</td>
                        <td>{{ $item['date'] }}</td>
                        <td>
                            @if(null !== $item['group_id'])
                                <a href="{{ route('transactions.show', [$item['group_id']]) }}">{{ $item['description'] }}</a>
                                @if('firefly_only' === $item['kind'])
                                    · <a href="{{ route('transactions.edit', [$item['group_id']]) }}">{{ __('firefly.edit') }}</a>
                                @endif
                            @else
                                {{ $item['description'] }}
                            @endif
                            @if('proposal' === $item['kind'] && isset($item['from'], $item['to']))
                                <small class="text-muted">{{ __('ext::statements.unexplained_proposal_amounts', ['from' => $item['from'], 'to' => $item['to']]) }}</small>
                            @endif
                        </td>
                        <td class="text-end">{{ $item['amount'] }}</td>
                    </tr>
                @endforeach
                <tr class="fw-bold @if($hasRemaining) table-warning @else table-success @endif">
                    <td colspan="3">{{ __('ext::statements.unexplained_remaining') }}</td>
                    <td class="text-end" id="ext-unexplained-remaining">{{ $remaining }}</td>
                </tr>
                </tbody>
            </table>
            @if(!$hasRemaining && 0 === count($unexplained['items']))
                <p class="text-muted px-3 py-2 mb-0">{{ __('ext::statements.unexplained_none') }}</p>
            @endif
        </div>
        @if($committed && $hasRemaining)
            <div class="card-footer">
                <p class="fw-bold mb-1">{{ __('ext::statements.unexplained_question', ['amount' => $remaining]) }}</p>
                <p class="small text-muted mb-2">{{ __('ext::statements.unexplained_question_help') }}</p>
                @if('no' === $decision)
                    <p class="small mb-2">{{ __('ext::statements.unexplained_was_declined') }}</p>
                @endif
                <form method="post" action="{{ route('ext.statements.unexplained', [$statement->id]) }}" class="d-inline">
                    <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                    <input type="hidden" name="amount" value="{{ $remaining }}"/>
                    <button type="submit" name="answer" value="yes" class="btn btn-warning btn-sm">{{ __('ext::statements.unexplained_yes') }}</button>
                    @if('no' !== $decision)
                        <button type="submit" name="answer" value="no" class="btn btn-outline-secondary btn-sm">{{ __('ext::statements.unexplained_no') }}</button>
                    @endif
                </form>
            </div>
        @endif
    </div>

    <form method="post" action="{{ route('ext.statements.commit', [$statement->id]) }}">
        <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
        <div class="card mb-3">
            <div class="card-header p-0 border-bottom-0">
                <ul class="nav nav-tabs" role="tablist">
                    @foreach(['missing' => count($missing), 'matched' => count($matched), 'proposals' => count($proposals), 'firefly_only' => count($fireflyOnly), 'warnings' => count($warnings)] as $tab => $count)
                        <li class="nav-item" role="presentation">
                            <button class="nav-link @if($loop->first) active @endif" id="ext-tab-{{ $tab }}" data-bs-toggle="tab" data-bs-target="#ext-pane-{{ $tab }}" type="button" role="tab">{{ __('ext::statements.tab_'.$tab) }} <span class="badge text-bg-secondary">{{ $count }}</span></button>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="card-body tab-content">
                <div class="tab-pane fade show active" id="ext-pane-missing" role="tabpanel">
                    @include('ext::statements.lines', ['lines' => $missing, 'select' => $committed ? null : 'create', 'checked' => true])
                </div>
                <div class="tab-pane fade" id="ext-pane-matched" role="tabpanel">
                    @include('ext::statements.lines', ['lines' => $matched, 'select' => null, 'checked' => false])
                </div>
                <div class="tab-pane fade" id="ext-pane-proposals" role="tabpanel">
                    @include('ext::statements.lines', ['lines' => $proposals, 'select' => $committed ? null : 'apply', 'checked' => false])
                </div>
                <div class="tab-pane fade" id="ext-pane-firefly_only" role="tabpanel">
                    @if(0 === count($fireflyOnly))
                        <p class="text-muted mb-0">{{ __('ext::statements.nothing') }}</p>
                    @else
                        <table class="table table-sm mb-0">
                            <thead><tr><th>{{ __('ext::statements.date') }}</th><th>{{ __('ext::statements.description') }}</th><th class="text-end">{{ __('ext::statements.amount') }}</th><th></th></tr></thead>
                            <tbody>
                            @foreach($fireflyOnly as $journal)
                                <tr>
                                    <td>{{ $journal['date'] }}</td>
                                    <td><a href="{{ route('transactions.show', [$journal['group_id']]) }}">{{ $journal['description'] }}</a></td>
                                    <td class="text-end">{{ $journal['amount'] }}</td>
                                    <td>{{ __('ext::statements.kind_'.$journal['kind']) }}
                                        @if('unexplained' === $journal['kind'])
                                            · <a href="{{ route('transactions.edit', [$journal['group_id']]) }}">{{ __('firefly.edit') }}</a>
                                            · <a href="{{ route('transactions.delete', [$journal['group_id']]) }}">{{ __('firefly.delete') }}</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
                <div class="tab-pane fade" id="ext-pane-warnings" role="tabpanel">
                    @forelse($warnings as $warning)
                        <div class="alert alert-warning py-1">{{ $warning }}</div>
                    @empty
                        <p class="text-muted mb-0">{{ __('ext::statements.nothing') }}</p>
                    @endforelse
                </div>
            </div>
            @if(!$committed)
                <div class="card-footer">
                    @if($hasRemaining)
                        <fieldset class="mb-2" id="ext-unexplained-question">
                            <legend class="fs-6 fw-bold mb-1">{{ __('ext::statements.unexplained_question', ['amount' => $remaining]) }}</legend>
                            <p class="small text-muted mb-1">{{ __('ext::statements.unexplained_question_help') }} {{ __('ext::statements.unexplained_question_commit') }}</p>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="book_unexplained" id="ext_book_yes" value="{{ $remaining }}" required>
                                <label class="form-check-label" for="ext_book_yes">{{ __('ext::statements.unexplained_yes') }}</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="book_unexplained" id="ext_book_no" value="no" required>
                                <label class="form-check-label" for="ext_book_no">{{ __('ext::statements.unexplained_no') }}</label>
                            </div>
                        </fieldset>
                    @endif
                    <button type="submit" class="btn btn-success"><em class="bi bi-check2-all"></em> {{ __('ext::statements.commit') }}</button>
                </div>
            @endif
        </div>
    </form>
@endsection
