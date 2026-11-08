<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>{{ $sprechtag->titel }} – Ihre Termine</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #1f2937; font-size: 12px; }
        .head { border-bottom: 2px solid #4f46e5; padding-bottom: 8px; margin-bottom: 14px; }
        .head h1 { margin: 0 0 2px; font-size: 18px; color: #111827; }
        .head .sub { font-size: 12px; color: #4b5563; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .03em;
             color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 4px 6px; }
        td { padding: 7px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        td.zeit { white-space: nowrap; font-weight: bold; }
        .leer { color: #6b7280; font-style: italic; }
        .fuss { margin-top: 16px; font-size: 10px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="head">
        <h1>{{ $sprechtag->titel }} – Ihre Termine</h1>
        <div class="sub">{{ $eltern->name }} · {{ $sprechtag->tageText() }}</div>
    </div>

    @if ($eintraege->isEmpty())
        <p class="leer">Sie haben noch keine Termine gebucht.</p>
    @else
        <table>
            <thead><tr><th>Wann</th><th>Lehrkraft</th><th>Für</th><th>Raum</th></tr></thead>
            <tbody>
                @foreach ($eintraege as $e)
                    <tr>
                        <td class="zeit">{{ $e['tag'] }}<br>{{ $e['von'] }}–{{ $e['bis'] }} Uhr</td>
                        <td>{{ $e['lehrer'] }}</td>
                        <td>{{ $e['kind'] }} <span style="color:#6b7280;">(Klasse {{ $e['klasse'] }})</span></td>
                        <td>{{ $e['raum'] ?: '–' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="fuss">Stand: {{ now()->format('d.m.Y, H:i') }} Uhr. Änderungen buchen Sie im Intranet unter Elternsprechtag → Sprechtermine.</p>
</body>
</html>
