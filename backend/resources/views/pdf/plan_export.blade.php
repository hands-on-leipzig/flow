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

@foreach ($programGroups as $programName => $roleTables)
    <h2 style="font-size:16px; margin-top:25px;">Programm: {{ $programName }}</h2>

    @foreach ($roleTables as $roleTable)
        @include('pdf.roles.team', ['roleTable' => $roleTable])
    @endforeach
@endforeach
</body>
</html>