@extends('mail.layout')

@section('preheader')
    {{ $personName }} möchte als {{ $role }} helfen.
@endsection

@section('eyebrow')
    Helfer:innen
@endsection

@section('headline')
    Neue Anfrage
@endsection

@section('content')
    <p style="margin:0 0 16px;font-family:{{ $fontStack }};color:#0f172a;">Für <strong>{{ $eventName }}</strong> ist eine Anfrage eingegangen.</p>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 16px;border:1px solid #e8ebf0;border-radius:12px;">
        <tr>
            <td style="padding:14px 16px;border-bottom:1px solid #e8ebf0;">
                <p style="margin:0 0 4px;font-family:{{ $fontStack }};font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b;">Name</p>
                <p style="margin:0;font-family:{{ $fontStack }};color:#0f172a;font-weight:700;">{{ $personName }}</p>
            </td>
        </tr>
        <tr>
            <td style="padding:14px 16px;">
                <p style="margin:0 0 4px;font-family:{{ $fontStack }};font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b;">Rolle</p>
                <p style="margin:0;font-family:{{ $fontStack }};color:#0f172a;font-weight:700;">{{ $role }}</p>
            </td>
        </tr>
    </table>
    <p style="margin:0;font-family:{{ $fontStack }};color:#475569;font-size:14px;">Bitte in FLOW unter Helfer:innen die Anfrage übernehmen oder ablehnen.</p>
@endsection
