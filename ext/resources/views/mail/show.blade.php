@extends('ext::layout.page')
@section('content')
    <div class="row">
        <div class="col-lg-6 col-md-12">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">{{ $message->subject }}</h3></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-4">{{ __('ext::mail.received') }}</dt><dd class="col-8">{{ $message->received_at?->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') }}</dd>
                        <dt class="col-4">{{ __('ext::mail.sender') }}</dt><dd class="col-8">{{ $message->sender }}</dd>
                        <dt class="col-4">{{ __('ext::mail.mailbox') }}</dt><dd class="col-8">{{ $message->mailbox?->name }}</dd>
                        <dt class="col-4">{{ __('ext::mail.outcome') }}</dt><dd class="col-8">{{ $message->outcome }}</dd>
                        <dt class="col-4">{{ __('ext::mail.status') }}</dt><dd class="col-8"><span class="badge text-bg-secondary">{{ $message->status }}</span></dd>
                        @if($message->error)<dt class="col-4">{{ __('ext::mail.error') }}</dt><dd class="col-8 text-danger">{{ $message->error }}</dd>@endif
                    </dl>
                </div>
                @if($canRetry)
                    <div class="card-footer">
                        <form method="post" action="{{ route('ext.mail.retry', [$message->id]) }}">
                            <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                            <button class="btn btn-sm btn-warning" type="submit"><em class="bi bi-arrow-repeat"></em> {{ __('ext::mail.retry') }}</button>
                        </form>
                    </div>
                @endif
            </div>
            @if($message->unmatched_text)
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">{{ __('ext::mail.unmatched_segments') }}</h3></div>
                    <div class="card-body"><pre class="mb-0 small" style="white-space: pre-wrap">{{ $message->unmatched_text }}</pre></div>
                </div>
            @endif
        </div>
        <div class="col-lg-6 col-md-12">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">{{ __('ext::mail.bodies') }}</h3></div>
                <div class="card-body">
                    @foreach((array) $message->bodies as $body)
                        <pre class="small border rounded p-2" style="white-space: pre-wrap">{{ $body }}</pre>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title">{{ __('ext::mail.transactions') }}</h3></div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>{{ __('ext::mail.date') }}</th><th>{{ __('ext::mail.amount') }}</th><th>{{ __('ext::mail.account') }}</th><th>{{ __('ext::mail.status') }}</th><th>{{ __('ext::mail.firefly') }}</th><th>{{ __('ext::mail.external_id') }}</th></tr></thead>
                <tbody>
                @foreach($transactions as $transaction)
                    <tr>
                        <td>{{ \Carbon\Carbon::parse($transaction->payload['occurred_at'])->format('Y-m-d H:i') }}</td>
                        <td>{{ 'deposit' === $transaction->payload['kind'] ? '' : '-' }}{{ $transaction->payload['amount'] }} {{ $transaction->payload['description'] }}</td>
                        <td>{{ $transaction->account?->name ?? $transaction->payload['account_label'] }}</td>
                        <td><span class="badge text-bg-secondary">{{ $transaction->status }}</span>@if($transaction->error)<div class="small text-danger">{{ $transaction->error }}</div>@endif</td>
                        <td>@if($transaction->transaction_group_id)<a href="{{ route('transactions.show', [$transaction->transaction_group_id]) }}">#{{ $transaction->transaction_group_id }}</a>@endif</td>
                        <td><code class="small">{{ $transaction->external_id }}</code></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
