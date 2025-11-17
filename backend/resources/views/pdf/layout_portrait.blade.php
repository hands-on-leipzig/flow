<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>{{ $title ?? 'Dokument' }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 120px 40px 100px 40px; /* top, right, bottom, left */
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
            bottom: -80px; /* entspricht margin-bottom von @page */
            left: 0;
            right: 0;
            height: 80px;
            padding-bottom: 15px;
        }

        /* Content */
        main {
            margin-top: 0;
        }

        /* Header/Footer Tables */
        table {
            width: 100%;
            border-collapse: collapse;
        }

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
            height: 70px;
        }

        .footer-logo-table img {
            max-width: 70px;
            max-height: 70px;
            display: inline-block;
        }

        .footer-timestamp {
            position: absolute;
            right: 10px;
            bottom: 5px;
            font-size: 8px;
            color: #999;
            font-family: sans-serif;
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
                            <img src="{{ $src }}" style="height:50px; width:auto; margin-right:8px;" />
                        @endforeach
                    @endif
                </td>
                <td style="width:34%; text-align:center;">
                    <div style="font-size:14px; margin-bottom:4px;">
                        {{ $header['centerTitleTop'] ?? '' }}
                    </div>
                    <div style="font-size:18px; font-weight:bold;">
                        {{ $header['centerTitleMain'] ?? '' }}
                    </div>
                </td>
                <td style="width:33%; text-align:right;">
                    @if(!empty($header['rightLogo']))
                        <img src="{{ $header['rightLogo'] }}" style="height:50px; width:auto;" />
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
