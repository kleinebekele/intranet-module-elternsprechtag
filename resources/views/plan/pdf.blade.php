@php
    use Intranet\Modules\Elternsprechtag\Support\Klassen;
    $nv = fn ($n) => \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($n);
    $klasse = fn ($kind) => $kind ? implode(', ', Klassen::vonKind($kind)) : '';
    $vorschau = ! empty($vorschau);
    // Im PDF aufeinanderfolgende freie Zeiten zu einer Zeile zusammenfassen – sonst wird
    // die Liste schnell länger als eine Seite. Die Vorschau zeigt jede Zeit einzeln.
    $zusammenfassen = function ($zeilen) {
        $aus = [];
        foreach ($zeilen as $z) {
            $frei = ! $z['pause'] && ! $z['termin'];
            $letzte = end($aus);
            if ($frei && $letzte && ($letzte['frei'] ?? false)) {
                $aus[array_key_last($aus)]['bis'] = $z['bis'];
                $aus[array_key_last($aus)]['anzahl']++;
            } else {
                $aus[] = $z + ['frei' => $frei, 'anzahl' => 1];
            }
        }

        return $aus;
    };
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
        @if ($vorschau)
            {{-- Vorschau im Terminplan (iframe): kein Seitenrand wie im PDF, also selbst etwas Luft. --}}
            body { margin: 20px; font-size: 13px; background: #fff; }
            td.notiz { width: 320px; }
            td.aktion { width: 1%; white-space: nowrap; padding-right: 10px; }
            .notizfeld { width: 100%; box-sizing: border-box; font-size: 12px; padding: 4px 6px; border: 1px solid #d1d5db;
                         border-radius: 6px; resize: vertical; min-height: 30px; }
            .notizfeld:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 2px #e0e7ff; }
            .gespeichert { font-size: 10px; color: #15803d; }
            .absage { margin: 0; font-size: 11px; color: #6b7280; }
            .absage span:not([hidden]) { display: block; white-space: normal; width: 9rem; }
            .link { background: none; border: 0; padding: 0; cursor: pointer; font-size: 11px; color: #b91c1c; text-decoration: underline; }
            .rot { font-weight: bold; }
        @endif
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
                <tr>@if ($vorschau)<th></th>@endif<th>Uhrzeit</th><th>Kind</th><th>Klasse</th><th>Eltern</th><th>Notiz</th></tr>
            </thead>
            <tbody>
                @foreach ($vorschau ? $t['zeilen'] : $zusammenfassen($t['zeilen']) as $z)
                    @if ($z['pause'])
                        <tr class="pause">@if ($vorschau)<td></td>@endif<td class="zeit">{{ $z['von'] }}–{{ $z['bis'] }}</td><td colspan="4">Pause</td></tr>
                    @elseif ($z['termin'])
                        <tr>
                            @if ($vorschau)
                                <td class="aktion">
                                    @if ($verwaltung || $z['termin']->beginn()->isFuture())
                                        {{-- Zweistufig statt confirm(): erst „absagen", dann „Ja, absagen". Nach dem Absenden lädt der ganze Terminplan neu. --}}
                                        <form method="POST" target="_top" class="absage"
                                              action="{{ route('module.elternsprechtag.plan.absagen', [$z['termin'], ...$param]) }}">
                                            @csrf @method('DELETE')
                                            <button type="button" class="link" onclick="this.hidden = true; this.nextElementSibling.hidden = false">absagen</button>
                                            <span hidden>Eltern bekommen eine Mail. <button type="submit" class="link rot">Ja, absagen</button></span>
                                        </form>
                                    @endif
                                </td>
                            @endif
                            <td class="zeit">{{ $z['von'] }}–{{ $z['bis'] }}</td>
                            <td>{{ $nv($z['termin']->kind?->name) }}
                                @if (in_array($z['termin']->kind_user_id, $wuensche)) <span class="wunsch">(Wunsch)</span> @endif
                                @if ($z['termin']->gruppe) <span class="wunsch">(gemeinsam mit {{ $partnerVon[$z['termin']->gruppe] ?? '' }})</span> @endif
                                @if ($z['termin']->allein) <span class="wunsch">(nur hier – gemeinsam war nichts frei)</span> @endif
                            </td>
                            <td class="klasse">{{ $klasse($z['termin']->kind) }}</td>
                            <td>{{ $nv($z['termin']->eltern?->name) }}</td>
                            @if ($vorschau)
                                <td class="notiz">
                                    <textarea class="notizfeld" rows="1" maxlength="2000" placeholder="Notiz … (wird automatisch gespeichert)"
                                              data-url="{{ route('module.elternsprechtag.plan.notiz', [$z['termin'], ...$param]) }}">{{ $z['termin']->notiz }}</textarea>
                                    <span class="gespeichert" hidden>gespeichert</span>
                                </td>
                            @else
                                <td class="notiz">{{ $z['termin']->notiz }}</td>
                            @endif
                        </tr>
                    @else
                        <tr>
                            @if ($vorschau)<td></td>@endif
                            <td class="zeit">{{ $z['von'] }}–{{ $z['bis'] }}</td>
                            <td class="frei" colspan="3">frei @if (($z['anzahl'] ?? 1) > 1)({{ $z['anzahl'] }} Termine)@endif</td>
                            <td class="notiz"></td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    @empty
        <p class="leer">Keine Tage eingetragen.</p>
    @endforelse

    @if ($beiPartner->isNotEmpty())
        <h2>Nur bei einer anderen Klassenlehrkraft gebucht</h2>
        <table>
            <thead><tr><th>Termin</th><th>Kind</th><th>Klasse</th><th>Bei</th></tr></thead>
            <tbody>
                @foreach ($beiPartner as $t)
                    <tr><td class="zeit">{{ $t->tag?->beschriftung() }}, {{ $t->von }}</td><td>{{ $nv($t->kind?->name) }}</td><td class="klasse">{{ $klasse($t->kind) }}</td><td>{{ $nv($t->plan?->user?->name) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($offeneWuensche->isNotEmpty())
        <h2>Gesprächswünsche ohne Termin</h2>
        <table>
            <thead><tr><th>Kind</th><th>Klasse</th><th>Notiz</th></tr></thead>
            <tbody>
                @foreach ($offeneWuensche as $w)
                    <tr><td>{{ $nv($w->kind->name) }}</td><td class="klasse">{{ $klasse($w->kind) }}</td><td>{{ $w->notiz }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($vorschau)
        <script>
            // Notizen speichern sich beim Tippen (kurz nach der letzten Taste) und beim Verlassen des Felds.
            document.querySelectorAll('.notizfeld').forEach((feld) => {
                const hinweis = feld.nextElementSibling;
                let warte = null, zuletzt = feld.value;
                const speichern = async () => {
                    clearTimeout(warte);
                    if (feld.value === zuletzt) return;
                    const wert = feld.value;
                    const antwort = await fetch(feld.dataset.url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                        body: JSON.stringify({ notiz: wert }),
                    });
                    if (antwort.ok) {
                        zuletzt = wert;
                        hinweis.textContent = 'gespeichert';
                        hinweis.style.color = '#15803d';
                    } else {
                        hinweis.textContent = 'nicht gespeichert – bitte erneut versuchen';
                        hinweis.style.color = '#b91c1c';
                    }
                    hinweis.hidden = false;
                    setTimeout(() => { hinweis.hidden = true; }, 2500);
                };
                feld.addEventListener('input', () => { clearTimeout(warte); warte = setTimeout(speichern, 800); });
                feld.addEventListener('blur', speichern);
            });
        </script>
    @endif
</body>
</html>
