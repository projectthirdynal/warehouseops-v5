@extends('meta.layout')

@section('content')
    <h1>{{ $appName }} Privacy Policy</h1>
    <p>This policy explains how {{ $appName }} processes data when used for Facebook Page connection, Messenger conversation handling, order processing, customer history lookup, and courier export workflows.</p>

    <h2>Data We Process</h2>
    <ul>
        <li>Facebook Login account data used to connect business Pages, such as Facebook user id, display name, email, and access tokens.</li>
        <li>Facebook Page metadata and subscription state needed to receive messages and comments.</li>
        <li>Messenger conversation data, including sender identifiers, message content, attachments, and delivery status.</li>
        <li>Operational order data such as customer name, phone number, address, products, courier details, and order remarks.</li>
    </ul>

    <h2>Why We Process This Data</h2>
    <ul>
        <li>To connect authorized Facebook Pages to the Shop workspace.</li>
        <li>To receive and respond to Facebook Page messages and comments.</li>
        <li>To create, validate, export, and report on customer orders inside WarehouseOps.</li>
        <li>To maintain security, auditability, and operational diagnostics for integrations.</li>
    </ul>

    <h2>Retention and Deletion</h2>
    <p>Facebook-connected account tokens and social identity records are removed or disconnected when a valid Meta data deletion request is received. Operational records such as orders, exports, and accounting-related data may be retained where needed for fraud prevention, tax, logistics, or legal compliance, but social identity links are removed when possible.</p>

    <h2>Contact</h2>
    <p>For privacy or deletion concerns, contact <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>

    <div class="meta">
        <div><strong>Privacy URL:</strong> <a href="{{ $appUrl }}/meta/privacy">{{ $appUrl }}/meta/privacy</a></div>
        <div><strong>Terms URL:</strong> <a href="{{ $appUrl }}/meta/terms">{{ $appUrl }}/meta/terms</a></div>
        <div><strong>Data deletion callback:</strong> <a href="{{ $appUrl }}/meta/data-deletion">{{ $appUrl }}/meta/data-deletion</a></div>
    </div>
@endsection
