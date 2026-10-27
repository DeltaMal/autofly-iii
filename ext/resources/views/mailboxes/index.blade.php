@extends('ext::layout.page')
@section('content')
    @if(!$hasClient)
        <div class="alert alert-warning">{{ __('ext::mail.no_client') }}</div>
    @endif
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ __('ext::mail.mailboxes') }}</h3>
            <div class="card-tools"><a class="btn btn-sm btn-success" href="{{ route('ext.mailboxes.create') }}"><em class="bi bi-plus"></em> {{ __('ext::mail.new_mailbox') }}</a></div>
        </div>
        <div class="card-body p-0">
            @if(0 === $mailboxes->count())
                <p class="p-3 mb-0 text-muted">{{ __('ext::mail.no_mailboxes') }}</p>
            @else
                <table class="table table-sm table-striped mb-0">
                    <thead><tr>
                        <th>{{ __('ext::mail.name') }}</th><th>{{ __('ext::mail.address') }}</th><th>{{ __('ext::mail.banks') }}</th><th>{{ __('ext::mail.mode') }}</th>
                        <th>{{ __('ext::mail.enabled') }}</th><th>{{ __('ext::mail.last_poll') }}</th><th>{{ __('ext::mail.last_error') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                    @foreach($mailboxes as $mailbox)
                        <tr>
                            <td><a href="{{ route('ext.mail.index', ['mailbox' => $mailbox->id]) }}">{{ $mailbox->name }}</a></td>
                            <td>{{ $mailbox->email_address }}</td>
                            <td>{{ implode(', ', array_map(static fn ($b) => isset($banks[$b]) ? $banks[$b]->name() : $b, (array) $mailbox->banks)) }}</td>
                            <td><span class="badge @if($mailbox->isShadow()) text-bg-warning @else text-bg-success @endif">{{ __('ext::mail.mode_'.$mailbox->mode) }}</span></td>
                            <td>@if($mailbox->enabled)<em class="bi bi-check-lg"></em>@else<em class="bi bi-x-lg"></em>@endif</td>
                            <td>{{ $mailbox->last_polled_at?->diffForHumans() ?? __('ext::mail.never') }}</td>
                            <td class="text-danger small">{{ \Illuminate\Support\Str::limit((string) $mailbox->last_error, 120) }}</td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('ext.mailboxes.connect', [$mailbox->id]) }}">{{ $mailbox->isConnected() ? __('ext::mail.reconnect') : __('ext::mail.connect') }}</a>
                                @if($mailbox->isConnected())
                                    <form class="d-inline" method="post" action="{{ route('ext.mailboxes.poll', [$mailbox->id]) }}">
                                        <input type="hidden" name="_token" value="{{ csrf_token() }}"/>
                                        <button class="btn btn-sm btn-outline-success" type="submit">{{ __('ext::mail.poll_now') }}</button>
                                    </form>
                                @endif
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('ext.mailboxes.edit', [$mailbox->id]) }}">{{ __('ext::mail.edit') }}</a>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endsection
