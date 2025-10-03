<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>{{ $title ?? 'Dokument' }}</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; color:#333; }
        .header, .footer { width:100%; }
        .header td, .footer td { vertical-align: top; }
        .logos img { height:80px; margin:0 5px; }
        .center { text-align: center; }
        .footer td { text-align:center; vertical-align:middle; padding:10px; }
    </style>
</head>
<body>

    {{-- HEADER --}}
    <table class="header" style="margin-bottom: 30px; border-collapse: collapse; width:100%;">
        <tr>
            <td style="width:33%; text-align:left;">
                @if(!empty($header['leftLogos']))
                    @foreach($header['leftLogos'] as $src)
                        <img src="{{ $src }}" style="height:80px; width:auto; margin-right:10px;" />
                    @endforeach
                @endif
            </td>
            <td style="width:34%; text-align:center;">
                <div style="font-size:20px; margin-bottom:6px;">
                    {{ $header['centerTitleTop'] ?? '' }}
                </div>
                <div style="font-size:28px; font-weight:bold;">
                    {{ $header['centerTitleMain'] ?? '' }}
                </div>
            </td>
            <td style="width:33%; text-align:right;">
                @if(!empty($header['rightLogo']))
                    <img src="{{ $header['rightLogo'] }}" style="height:80px; width:auto;" />
                @endif
            </td>
        </tr>
    </table>

    {{-- CONTENT --}}
    {!! $contentHtml !!}

    {{-- FOOTER --}}
    @if(!empty($footerLogos))
        <table class="footer" style="margin-top: 40px; border-collapse: collapse; width:100%;">
            <tr>
                @foreach($footerLogos as $src)
                    <td>
                        <img src="{{ $src }}" style="height:80px; max-width:100%; object-fit:contain;" />
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

</body>
</html>