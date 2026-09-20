@extends('mail.layout')

@section('preheader')
    Code {{ $code }} für {{ $eventName }}
@endsection

@section('eyebrow')
    Anmeldecode
@endsection

@section('headline')
    {{ $eventName }}
@endsection

@section('content')
    <p style="margin:0 0 18px;font-family:{{ $fontStack }};color:#0f172a;">Code für das Formular zur Veranstaltung:</p>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 18px;">
        <tr>
            <td align="center" bgcolor="#fff7ed" style="background:#fff7ed;border:1px solid #ffd6a8;border-radius:12px;padding:18px 12px;">
                <span style="font-family:Consolas,'Courier New',Courier,monospace;font-size:32px;letter-spacing:.28em;font-weight:700;color:#0f172a;">{{ $code }}</span>
            </td>
        </tr>
    </table>
    <p style="margin:0;font-family:{{ $fontStack }};color:#475569;font-size:14px;">Der Code läuft in 15 Minuten ab.</p>
@endsection
