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
            <table>
                <tr>
                    @foreach($footerLogos as $src)
                        <td style="text-align:center; vertical-align:middle; padding:10px;">
                            <img src="{{ $src }}" style="height:60px; max-width:100%; object-fit:contain;" />
                        </td>
                    @endforeach
                </tr>
            </table>
        @endif
        <div style="position:absolute; bottom:5px; right:10px; font-size:8px; color:#999; font-family:sans-serif;">
            {{ now()->timezone('Europe/Berlin')->format('d.m.Y H:i') }}
        </div>
    </footer>

    {{-- CONTENT --}}
    <main>
        {!! $contentHtml !!}
    </main>

</body>
</html>
