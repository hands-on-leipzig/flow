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
<h1 style="font-size:18px; margin-bottom:20px;">Plan Export</h1>

@foreach ($programGroups as $programName => $roles)
    <h1 style="font-size:18px; margin-top:25px;">{{ $programName }}</h1>

    @foreach ($roles as $roleBlock)
        <h2 style="font-size:16px; margin-top:20px;">{{ $roleBlock['role'] }}</h2>

        {{-- Falls Teams existieren --}}
        @if (!empty($roleBlock['teams']))
            @foreach ($roleBlock['teams'] as $teamTable)
                @include('pdf.roles.team', ['teamTable' => $teamTable])
            @endforeach
        @endif

        {{-- Falls Lanes existieren --}}
        @if (!empty($roleBlock['lanes']))
            @foreach ($roleBlock['lanes'] as $laneTable)
                @include('pdf.roles.lane', ['laneTable' => $laneTable])
            @endforeach
        @endif
    @endforeach
@endforeach
</body>
</html>