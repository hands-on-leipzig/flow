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
    <h1>Rollen</h1>

    @foreach($programGroups as $programName => $roleTables)
        <h2>{{ $programName }}</h2>

        @foreach($roleTables as $roleTable)
            {{-- nur Differenzierung nach Team behandeln --}}
            @if(isset($roleTable['teamLabel']))
                @include('pdf.roles.team', ['roleTable' => $roleTable])
            @endif
        @endforeach
    @endforeach
</body>
</html>