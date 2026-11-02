@extends('mail.layout')

@section('preheader')
    Die Frist für die Teamdaten von {{ $eventName }} ist jetzt der {{ $deadline }}.
@endsection

@section('eyebrow')
    Teamdaten
@endsection

@section('headline')
    {{ $eventName }}
@endsection

@section('content')
    @if ($firstname !== '')
        <p style="margin:0 0 16px;font-family:{{ $fontStack }};color:#0f172a;">Hallo {{ $firstname }},</p>
    @endif
    <p style="margin:0 0 16px;font-family:{{ $fontStack }};color:#0f172a;">
        Die Frist für die Teamdaten von <strong>{{ $eventName }}</strong> ist jetzt der <strong>{{ $deadline }}</strong>.
    </p>
    <p style="margin:0 0 16px;font-family:{{ $fontStack }};color:#0f172a;">
        Bitte prüfe Name, Geschlecht und Geburtsdatum in JOIN und setze „Teamdaten komplett“, wenn alles vollständig ist.
    </p>
@endsection
