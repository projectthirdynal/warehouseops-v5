@extends('meta.layout')

@section('content')
    <h1>{{ $appName }} Terms of Service</h1>
    <p>These terms govern use of {{ $appName }} as an internal operational platform for Facebook Page inbox handling, order processing, customer support, reporting, and logistics coordination.</p>

    <h2>Authorized Use</h2>
    <ul>
        <li>Only authorized staff, agents, and administrators may connect Facebook Pages and operate the Shop workspace.</li>
        <li>Users must only connect Pages, businesses, and customer data they are permitted to manage.</li>
        <li>Users are responsible for maintaining valid permissions in Meta, QuickBooks, courier systems, and related third-party services.</li>
    </ul>

    <h2>Data Handling</h2>
    <ul>
        <li>Users must handle customer data in line with applicable privacy, tax, and consumer-protection laws.</li>
        <li>Data may be exported to couriers, finance systems, and internal reports only for legitimate operational purposes.</li>
        <li>Misuse of Facebook messaging data, customer identities, or order history is prohibited.</li>
    </ul>

    <h2>Availability and Integrations</h2>
    <p>Features that depend on Meta, courier APIs, or accounting providers may be unavailable or limited when third-party permissions, tokens, or services fail. Access can be suspended when a connected integration is revoked or non-compliant.</p>

    <h2>Contact</h2>
    <p>Operational and legal concerns can be sent to <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
@endsection
