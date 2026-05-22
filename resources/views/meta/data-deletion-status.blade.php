@extends('meta.layout')

@section('content')
    <h1>Meta Data Deletion Status</h1>
    <p>This page confirms that {{ $appName }} received and processed a Meta data deletion request.</p>

    <div class="success">
        <strong>Status:</strong> {{ strtoupper($deletionRequest->status) }}<br>
        <strong>Confirmation code:</strong> {{ $deletionRequest->confirmation_code }}
    </div>

    <div class="meta">
        <div><strong>Requested at:</strong> {{ optional($deletionRequest->requested_at)->toDayDateTimeString() }}</div>
        <div><strong>Completed at:</strong> {{ optional($deletionRequest->completed_at)->toDayDateTimeString() ?? 'Pending' }}</div>
        @if($deletionRequest->app_scoped_user_id)
            <div><strong>App-scoped user id:</strong> {{ $deletionRequest->app_scoped_user_id }}</div>
        @endif
    </div>

    @php($summary = $deletionRequest->result_summary ?? [])

    @if(! empty($summary))
        <h2>Processed Items</h2>
        <div class="meta">
            <div><strong>Facebook accounts deleted:</strong> {{ $summary['facebook_accounts_deleted'] ?? 0 }}</div>
            <div><strong>Facebook Pages disconnected:</strong> {{ $summary['facebook_pages_disconnected'] ?? 0 }}</div>
            <div><strong>Customer identities deleted:</strong> {{ $summary['customer_identities_deleted'] ?? 0 }}</div>
            <div><strong>Conversations deleted:</strong> {{ $summary['conversations_deleted'] ?? 0 }}</div>
            <div><strong>Messages deleted:</strong> {{ $summary['messages_deleted'] ?? 0 }}</div>
            <div><strong>Webhook events deleted:</strong> {{ $summary['webhook_events_deleted'] ?? 0 }}</div>
            <div><strong>Customers anonymized:</strong> {{ $summary['customers_anonymized'] ?? 0 }}</div>
        </div>

        @if(! empty($summary['notes']))
            <h2>Notes</h2>
            <ul>
                @foreach($summary['notes'] as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        @endif
    @endif
@endsection
