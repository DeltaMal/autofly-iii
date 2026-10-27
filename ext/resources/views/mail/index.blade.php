@extends('ext::layout.page')
@section('content')
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">{{ __('ext::mail.activity') }}</h3>
            <div class="card-tools">
                <form method="get" action="{{ route('ext.mail.index') }}" class="d-flex gap-1">
                    <select class="form-select form-select-sm" name="status" aria-label="{{ __('ext::mail.status') }}">
                        <option value="">{{ __('ext::mail.status') }}: {{ __('ext::mail.all') }}</option>
                        @foreach($statuses as $s)<option value="{{ $s }}" @if($status === $s) selected @endif>{{ $s }}</option>@endforeach
                    </select>
                    <select class="form-select form-select-sm" name="mailbox" aria-label="{{ __('ext::mail.mailbox') }}">
                        <option value="0">{{ __('ext::mail.mailbox') }}: {{ __('ext::mail.all') }}</option>
                        @foreach($mailboxes as $m)<option value="{{ $m->id }}" @if($mailboxId === $m->id) selected @endif>{{ $m->name }}</option>@endforeach
                    </select>
                    <button class="btn btn-sm btn-outline-primary" type="submit">{{ __('ext::mail.filter') }}</button>
                </form>
            </div>
        </div>
        <div class="card-body p-0">
            @if(0 === $messages->count())
                <p class="p-3 mb-0 text-muted">{{ __('ext::mail.no_messages') }}</p>
            @else
                <table class="table table-sm table-striped mb-0">
                    <thead><tr><th>{{ __('ext::mail.received') }}</th><th>{{ __('ext::mail.mailbox') }}</th><th>{{ __('ext::mail.subject') }}</th><th>{{ __('ext::mail.status') }}</th><th class="text-end">{{ __('ext::mail.transactions') }}</th></tr></thead>
                    <tbody>
                    @foreach($messages as $message)
                        <tr>
                            <td class="text-nowrap">{{ $message->received_at?->setTimezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                            <td>{{ $message->mailbox?->name }}</td>
                            <td><a href="{{ route('ext.mail.show', [$message->id]) }}">{{ \Illuminate\Support\Str::limit((string) $message->subject, 90) }}</a></td>
                            <td><span class="badge text-bg-secondary">{{ $message->status }}</span></td>
                            <td class="text-end">{{ $message->transactions->count() }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        @if($messages->hasPages())<div class="card-footer">{{ $messages->links() }}</div>@endif
    </div>
@endsection
