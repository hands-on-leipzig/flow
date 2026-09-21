@extends('mail.layout')

@section('preheader')
    Testmail aus FLOW.
@endsection

@section('eyebrow')
    Testversand
@endsection

@section('headline')
    Mailversand funktioniert
@endsection

@section('content')
    <p style="margin:0 0 12px;font-family:{{ $fontStack }};color:#0f172a;">Das ist eine Testmail aus FLOW.</p>
    <p style="margin:0;font-family:{{ $fontStack }};color:#0f172a;">Wenn sie ankommt, klappt der Weg über Microsoft Graph.</p>
@endsection
