@php
    use Intranet\Modules\Elternsprechtag\Support\Klassen;
    $klasse = fn ($kind) => $kind ? implode(', ', Klassen::vonKind($kind)) : '';
@endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Terminliste {{ $lehrer->name }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #1f2937; font-size: 11px; }
        .head { border-bottom: 2px solid #4f46e5; padding-bottom: 8px; margin-bottom: 14px; }
        .head h1 { margin: 0 0 2px; font-size: 18px; color: #111827; }
        .head .sub { font-size: 12px; color: #4b5563; }
        h2 { font-size: 13px; margin: 16px 0 6px; color: #111827; }
        h2 .zeit { font-weight: normal; color: #6b7280; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .03em;
             color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 4px 6px; }
        td { padding: 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        td.zeit { width: 80px; white-space: nowrap; font-weight: bold; }
        td.klasse { width: 60px; color: #4b5563; }
        td.notiz { width: 160px; }
        tr.pause td { background: #f3f4f6; color: #6b7280; font-style: italic; }
        .frei { color: #9ca3af; }
        .wunsch { color: #4338ca; font-size: 9px; }
        .seite { page-break-inside: avoid; }
        .leer { color: #6b7280; font-style: italic; }
    </style>
</head>
<body>
    <div class="head">
        <h1>{{ $sprechtag->titel }} – {{ $lehrer->name }}</h1>
        <div class="sub">
            @if ($plan->raum) Raum: {{ $plan->raum }} · @endif
            Gesprächsdauer {{ $plan->slot_minuten }} Min.@if ($plan->vorbereitung_minuten) · Vorbereitungszeit {{ $plan->vorbereitung_minuten }} Min.@endif
            · Stand {{ now()->format('d.m.Y H:i') }}
        </div>
    </div>

    @forelse ($tage as $t)
        <h2>{{ $t['tag']->beschriftung() }} <span class="zeit">{{ $t['von'] }}–{{ $t['bis'] }} Uhr</span></h2>
        <table>
            <thead>
                <tr><th>Uhrzeit</th><th>Kind</th><th>Klasse</th><th>Eltern</th><th>Notiz</th></tr>
            </thead>
            <tbody>
                @foreach ($t['zeilen'] as $z)
                    @if ($z['pause'])
                        <tr class="pause"><td class="zeit">{{ $z['von'] }}–{{ $z['bis'] }}</td><td colspan="4">Pause</td></tr>
                    @elseif ($z['termin'])
                        <tr>
                            <td class="zeit">{{ $z['von'] }}–{{ $z['bis'] }}</td>
                            <td>{{ $z['termin']->kind?->name }}
                                @if (in_array($z['termin']->kind_user_id, $wuensche)) <span class="wunsch">(Wunsch)</span> @endif
                            </td>
                            <td class="klasse">{{ $klasse($z['termin']->kind) }}</td>
                            <td>{{ $z['termin']->eltern?->name }}</td>
                            <td class="notiz"></td>
                        </tr>
                    @else
                        <tr>
                            <td class="zeit">{{ $z['von'] }}–{{ $z['bis'] }}</td>
                            <td class="frei" colspan="3">frei</td>
                            <td class="notiz"></td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    @empty
        <p class="leer">Keine Tage eingetragen.</p>
    @endforelse

    @if ($offeneWuensche->isNotEmpty())
        <h2>Gesprächswünsche ohne Termin</h2>
        <table>
            <thead><tr><th>Kind</th><th>Klasse</th><th>Notiz</th></tr></thead>
            <tbody>
                @foreach ($offeneWuensche as $w)
                    <tr><td>{{ $w->kind->name }}</td><td class="klasse">{{ $klasse($w->kind) }}</td><td>{{ $w->notiz }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
