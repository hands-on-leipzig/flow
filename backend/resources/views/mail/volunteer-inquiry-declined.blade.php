@extends('mail.layout')

@section('preheader')
    Rückmeldung zur Veranstaltung {{ $eventName }}
@endsection

@section('eyebrow')
    Rückmeldung
@endsection

@section('headline')
    Diesmal nicht aufgenommen
@endsection

@section('content')
    <p style="margin:0;font-family:{{ $fontStack }};color:#0f172a;">Die Anfrage zur Veranstaltung <strong>{{ $eventName }}</strong> kann diesmal nicht aufgenommen werden.</p>
@endsection
