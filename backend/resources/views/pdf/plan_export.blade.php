{{-- resources/views/pdf/plan_export.blade.php --}}

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Plan Export</title>
    <style>
        body { font-family: sans-serif; font-size: 12px; }
        h1 { margin-top: 40px; font-size: 20px; }
        h2 { margin-top: 30px; font-size: 16px; }
        h3 { margin-top: 20px; font-size: 14px; }
        table { border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #333; padding: 4px; font-size: 11px; }
    </style>
</head>
<body>
<h1 style="font-size:18px; margin-bottom:5px;">
  {{ $eventName }} – {{ $eventDate }}
</h1>
<p style="font-size:11px; color:#555; margin-bottom:20px;">
  Letzte Änderung: {{ $lastUpdated }}
</p>

@foreach ($programGroups as $programName => $roles)
    <h1 style="font-size:18px; margin-top:25px;">{{ $programName }}</h1>

    @foreach ($roles as $roleBlock)
        <h2 style="font-size:16px; margin-top:20px;">{{ $roleBlock['role'] }}</h2>

        {{-- Falls Teams existieren --}}
        @if (!empty($roleBlock['teams']))
            @foreach ($roleBlock['teams'] as $teamTable)
                @include('pdf.plan_export.team', ['teamTable' => $teamTable])
            @endforeach
        @endif

        {{-- Falls Lanes existieren --}}
        @if (!empty($roleBlock['lanes']))
            @foreach ($roleBlock['lanes'] as $laneTable)
                @include('pdf.plan_export.lane', ['laneTable' => $laneTable])
            @endforeach
        @endif

        {{-- Falls Tables existieren --}}
        @if (!empty($roleBlock['tables']))
            @foreach ($roleBlock['tables'] as $tableBlock)
                @include('pdf.plan_export.table', ['tableBlock' => $tableBlock])
            @endforeach
        @endif

        {{-- Falls General-Block existiert --}}
        @if (!empty($roleBlock['general']))
            @foreach ($roleBlock['general'] as $generalBlock)
                @include('pdf.plan_export.general', ['generalBlock' => $generalBlock])
            @endforeach
        @endif

    @endforeach
@endforeach
</body>
</html>
