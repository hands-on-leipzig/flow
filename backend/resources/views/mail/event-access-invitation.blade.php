@extends('mail.layout')

@section('preheader')
    Einladung zur Veranstaltung {{ $eventName }}
@endsection

@section('eyebrow')
    Einladung
@endsection

@section('headline')
    {{ $eventName }}
@endsection

@section('content')
    <p style="margin:0 0 16px;font-family:{{ $fontStack }};color:#0f172a;">
        Du bist für diese Veranstaltung eingeladen. Die Einladung gilt nur für diese eine Veranstaltung.
    </p>
    <p style="margin:0 0 16px;font-family:{{ $fontStack }};color:#0f172a;">
        Berechtigung: <strong>{{ implode(', ', $permissions) }}</strong>
    </p>
    <p style="margin:0 0 20px;font-family:{{ $fontStack }};color:#0f172a;">
        Bitte melde dich bis {{ $expiresOn }} mit dieser E-Mail-Adresse an. Danach verfällt die Einladung.
    </p>
    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 8px;">
        <tr>
            <td bgcolor="#ff7a00" style="background:#ff7a00;border-radius:8px;">
                <a href="{{ $loginUrl }}" style="display:inline-block;padding:12px 18px;font-family:{{ $fontStack }};font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">
                    Bei FLOW anmelden
                </a>
            </td>
        </tr>
    </table>
@endsection
