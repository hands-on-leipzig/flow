<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>{{ $title ?? 'Dokument' }}</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 120px 40px {{ $isQrCodePdf ?? false ? '40px' : '100px' }} 40px; /* top, right, bottom, left */
        }

        body {
            font-family: sans-serif;
            font-size: 12px;
            color:#333;
        }

        /* Header */
        header {
            position: fixed;
            top: -100px;   /* entspricht margin-top von @page */
            left: 0;
            right: 0;
            height: 100px;
        }

        /* Footer */
        footer {
            position: fixed;
            bottom: {{ $isQrCodePdf ?? false ? '-40px' : '-80px' }}; /* entspricht margin-bottom von @page */
            left: 0;
            right: 0;
            height: {{ $isQrCodePdf ?? false ? '40px' : '80px' }};
            padding-bottom: {{ $isQrCodePdf ?? false ? '5px' : '15px' }};
        }

        .logos img { height:80px; margin:0 5px; }
        .center { text-align: center; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }

        .footer-logo-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0 auto;
            margin-bottom: 5px;
        }

        .footer-logo-table td {
            padding: 8px 12px;
            text-align: center;
            vertical-align: middle;
            height: 80px;
        }

        .footer-timestamp {
            position: absolute;
            right: 10px;
            bottom: {{ $isQrCodePdf ?? false ? '2px' : '5px' }};
            font-size: 8px;
            color: #999;
            font-family: sans-serif;
        }

        .footer-logo-table img {
            max-width: 80px;
            max-height: 80px;
            display: inline-block;
        }
    </style>
</head>
<body>

    {{-- HEADER --}}
    <header>
        <table>
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
                        @php
                            $titleTop = $header['centerTitleTop'] ?? '';
                            // Make FIRST italic and add line break after League
                            $titleTop = preg_replace('/FIRST LEGO League/', '<i>FIRST</i> LEGO League<br>', $titleTop);
                        @endphp
                        {!! $titleTop !!}
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
    </header>

    {{-- FOOTER --}}
    <footer>
        @if(!empty($footerLogos))
            <table class="footer-logo-table">
                <tr>
                    @foreach($footerLogos as $src)
                        <td>
                            <img src="{{ $src }}" alt="Footer logo" />
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif
        <div class="footer-timestamp">
            {{ now()->timezone('Europe/Berlin')->format('d.m.Y H:i') }}
        </div>
    </footer>

    {{-- CONTENT --}}
    <main>
        {!! $contentHtml !!}
    </main>

</body>
</html>