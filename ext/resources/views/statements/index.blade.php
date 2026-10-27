@extends('ext::layout.page')
@section('content')
    <div class="row">
        <div class="col-lg-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">{{ __('ext::statements.title') }}</h3>
                    <div class="card-tools">
                        <a href="{{ route('ext.statements.create') }}" class="btn btn-sm btn-success"><em class="bi bi-upload"></em> {{ __('ext::statements.upload') }}</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    @if(0 === $statements->count())
                        <p class="p-3 mb-0 text-muted">{{ __('ext::statements.no_statements') }}</p>
                    @else
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                            <tr>
                                <th>{{ __('ext::statements.uploaded') }}</th>
                                <th>{{ __('ext::statements.account') }}</th>
                                <th>{{ __('ext::statements.period') }}</th>
                                <th>{{ __('ext::statements.status') }}</th>
                                <th>{{ __('ext::statements.counts') }}</th>
                                <th class="text-end">{{ __('ext::statements.difference') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($statements as $statement)
                                <tr>
                                    <td><a href="{{ route('ext.statements.show', [$statement->id]) }}">{{ $statement->created_at->isoFormat($monthAndDayFormat) }}</a><br><small class="text-muted">{{ $statement->file_name }}</small></td>
                                    <td>@if($statement->account)<a href="{{ route('accounts.show', [$statement->account_id]) }}">{{ $statement->account->name }}</a>@endif</td>
                                    <td>{{ $statement->period_start->format('Y-m-d') }} – {{ $statement->period_end->format('Y-m-d') }}</td>
                                    <td>{{ __('ext::statements.status_'.$statement->status) }}</td>
                                    <td>
                                        @foreach((array) ($statement->summary['counts'] ?? []) as $status => $count)
                                            @if($count > 0 && !str_starts_with($status, 'firefly_'))<span class="badge text-bg-secondary">{{ $status }}: {{ $count }}</span>@endif
                                        @endforeach
                                    </td>
                                    <td class="text-end">{{ $statement->summary['breakdown']['unexplained'] ?? '' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
                @if($statements->hasPages())
                    <div class="card-footer">{{ $statements->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@endsection
