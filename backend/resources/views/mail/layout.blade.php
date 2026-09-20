<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <title>FLOW</title>
    @if (!empty($fontFaceCss))
        <style type="text/css">{{ $fontFaceCss }}</style>
    @endif
</head>
<body style="margin:0;padding:0;background:#e8ebf0;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
    @yield('preheader')
</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" bgcolor="#e8ebf0" style="background:#e8ebf0;margin:0;padding:0;width:100%;">
    <tr>
        <td align="center" style="padding:28px 12px;">
            <table role="presentation" width="600" cellspacing="0" cellpadding="0" bgcolor="#ffffff" style="width:100%;max-width:600px;border-collapse:collapse;background:#ffffff;border:1px solid #d8dee8;">
                <tr>
                    <td bgcolor="#ffffff" style="background:#ffffff;padding:24px 32px 18px;border-bottom:1px solid #e8ebf0;text-align:left;">
                        @if (!empty($logoSrc))
                            <img src="{{ $logoSrc }}" alt="FLOW" width="168" style="display:block;border:0;height:auto;width:168px;max-width:168px;">
                        @else
                            <span style="font-family:{{ $fontStack }};font-size:28px;font-weight:700;color:#0f172a;">FLOW</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td bgcolor="#ffffff" style="background:#ffffff;padding:28px 32px 8px;font-family:{{ $fontStack }};color:#0f172a;font-size:16px;line-height:1.6;">
                        @hasSection('eyebrow')
                            <p style="margin:0 0 8px;font-family:{{ $fontStack }};font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#ff7a00;font-weight:700;">@yield('eyebrow')</p>
                        @endif
                        @hasSection('headline')
                            <h1 style="margin:0 0 16px;font-family:{{ $fontStack }};font-size:22px;line-height:1.3;color:#0f172a;font-weight:700;">@yield('headline')</h1>
                        @endif
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td bgcolor="#ffffff" style="background:#ffffff;padding:8px 32px 28px;font-family:{{ $fontStack }};">&nbsp;</td>
                </tr>
                <tr>
                    <td bgcolor="#f4f6f9" style="background:#f4f6f9;padding:18px 32px;border-top:1px solid #e8ebf0;font-family:{{ $fontStack }};">
                        <table role="presentation" cellspacing="0" cellpadding="0">
                            <tr>
                                <td style="padding-right:12px;vertical-align:middle;">
                                    @if (!empty($hotLogoSrc))
                                        <img src="{{ $hotLogoSrc }}" alt="HANDS on TECHNOLOGY" width="120" style="display:block;border:0;height:auto;width:120px;max-width:120px;">
                                    @endif
                                </td>
                                <td style="vertical-align:middle;font-family:{{ $fontStack }};font-size:12px;line-height:1.45;color:#64748b;">
                                    HANDS on TECHNOLOGY e.V.
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
