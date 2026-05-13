@extends('meta.layout')

@section('content')
    <h1>{{ $appName }} Meta Data Deletion</h1>
    <p>This endpoint is used for Meta platform data deletion callbacks. When Meta sends a valid signed request, {{ $appName }} records the request, removes Facebook-linked access data, and provides a deletion status URL with a confirmation code.</p>

    <div class="notice">
        <strong>Callback URL</strong><br>
        <code>{{ $callbackUrl }}</code>
    </div>

    <h2>What This Removes</h2>
    <ul>
        <li>Connected Facebook Login account tokens and account metadata for the requesting app-scoped user id.</li>
        <li>Facebook customer identity rows that exactly match the provided user id.</li>
        <li>Related conversations, messages, and matching stored webhook sender records when a Facebook-linked customer identity can be matched.</li>
    </ul>

    <h2>What May Be Retained</h2>
    <p>Operational records such as finalized orders, accounting records, logistics exports, and fraud or audit logs may be retained when they are required for contractual, tax, or legal reasons. In those cases, Facebook identity links are removed where possible.</p>

    <h2>Manual Requests</h2>
    <p>If a user needs help beyond the automated Meta callback, contact <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
@endsection
