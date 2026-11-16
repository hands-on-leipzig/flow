@extends('mail.layout')

@section('preheader')
    Der Regionalpartner hat die Daten des Teams {{ $teamName }} geändert.
@endsection

@section('eyebrow')
    Teamdaten
@endsection

@section('headline')
    {{ $eventName }}
@endsection

@section('content')
    <p style="margin:0 0 16px;font-family:{{ $fontStack }};color:#0f172a;">
        Der Regionalpartner hat die Daten des Teams <strong>{{ $teamName }}</strong> geändert.
    </p>
    <p style="margin:0 0 20px;font-family:{{ $fontStack }};color:#0f172a;">
        Bitte prüfe die Teamdaten in FLOW.
    </p>
    <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 8px;">
        <tr>
            <td bgcolor="#ff7a00" style="background:#ff7a00;border-radius:8px;">
                <a href="{{ $flowUrl }}" style="display:inline-block;padding:12px 18px;font-family:{{ $fontStack }};font-size:15px;font-weight:700;color:#ffffff;text-decoration:none;">
                    In FLOW ansehen
                </a>
            </td>
        </tr>
    </table>
@endsection
