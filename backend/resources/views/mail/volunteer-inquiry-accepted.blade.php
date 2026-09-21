@extends('mail.layout')

@section('preheader')
    Aufnahme auf die Helfer:innenliste: {{ $eventName }}
@endsection

@section('eyebrow')
    Helfer:innenliste
@endsection

@section('headline')
    Anfrage aufgenommen
@endsection

@section('content')
    <p style="margin:0;font-family:{{ $fontStack }};color:#0f172a;">Die Aufnahme auf die Helfer:innenliste zur Veranstaltung <strong>{{ $eventName }}</strong> ist erfolgt.</p>
@endsection
