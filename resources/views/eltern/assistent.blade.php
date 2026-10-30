{{-- Termin-Assistent je Sprechtag. Erwartet: $s (Sprechtag), $eintrag (Angebot), $vorschlag (aus der Sitzung oder null), $als --}}
@php
    $nv = fn ($n) => \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($n);
    // Kinder nur mit Vornamen; bei mehreren Kindern unterstrichen, damit man sie auseinanderhält.
    $mehrereKinder = $eintrag['kinder']->count() > 1;
    $kn = fn ($kind) => $mehrereKinder
        ? '<span class="text-gray-800 underline">'.e(\Intranet\Modules\Elternsprechtag\Support\Namen::vorname($kind->name)).'</span>'
        : e(\Intranet\Modules\Elternsprechtag\Support\Namen::vorname($kind->name));
    $eingaben = $vorschlag['eingaben'] ?? [];
    $paare = $eintrag['kinder']->flatMap(fn ($z) => $z['lehrer']->map(fn ($l) => [
        'key' => $l['plan']->id.'-'.$z['kind']->id, 'kind' => $z['kind'], 'plan' => $l['plan'], 'termin' => $l['termin'], 'wunsch' => $l['wunsch'],
        'klassenlehrer' => $l['klassenlehrer'],
    ]))->sortBy(fn ($p) => $p['klassenlehrer'] ? 0 : 1)->keyBy('key');
    $prioText = [1 => 'unbedingt', 2 => 'gern', 3 => 'wenn es passt'];
    $prioFarbe = [1 => 'bg-indigo-600 text-white', 2 => 'bg-indigo-100 text-indigo-800', 3 => 'bg-gray-100 text-gray-700'];
    $tagMitSlots = $eintrag['kinder']->flatMap(fn ($z) => $z['lehrer']->flatMap(fn ($l) => collect($l['slots'])->pluck('tag_id')))->unique()->all();
@endphp

<div id="assistent-{{ $s->id }}" class="mt-4">
    <p class="mb-4 text-sm text-gray-600">Sagen Sie, mit wem Sie sprechen möchten – der Assistent schlägt eine Abfolge mit möglichst wenig Wartezeit vor.</p>

    @if ($paare->isEmpty())
        <p class="text-sm text-gray-400">Für die Klassen Ihrer Kinder hat noch keine Lehrkraft Gesprächszeiten eingetragen.</p>
    @else
        <form method="POST" action="{{ route('module.elternsprechtag.eltern.assistent', [$s] + $als) }}">
            @csrf
            <h4 class="text-sm font-semibold text-gray-800">1. Mit wem möchten Sie sprechen?</h4>
            <p class="mt-1 text-xs text-gray-500">1 = unbedingt · 2 = gern · 3 = wenn es passt. Wichtigere Gespräche haben Vorrang, wenn nicht alle unterzubringen sind.</p>
            <div class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-2 2xl:grid-cols-3">
                @foreach ($paare as $key => $p)
                    <div class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 {{ $p['termin'] ? 'border border-green-200 bg-green-50/60' : ($p['klassenlehrer'] ? 'border-2 border-indigo-400 bg-indigo-50/60' : 'border border-gray-200') }}">
                        <div class="min-w-0 text-sm">
                            <div class="font-medium text-gray-800">{{ $nv($p['plan']->user->name) }}
                                @if ($p['klassenlehrer'])
                                    <span class="ml-1 rounded-full bg-indigo-600 px-2 py-0.5 text-xs font-medium text-white">Klassenlehrkraft</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-500">für {!! $kn($p['kind']) !!}@if ($p['plan']->raum) · Raum {{ $p['plan']->raum }}@endif
                                @if ($p['wunsch'] && ! $p['termin']) · <span class="text-indigo-700">möchte Sie gern sprechen</span>@endif</div>
                        </div>
                        @if ($p['termin'])
                            <span class="whitespace-nowrap text-xs font-medium text-green-800">gebucht {{ $p['termin']->von }} Uhr</span>
                        @else
                            {{-- Vorbelegt mit 1: Gesprächswunsch der Lehrkraft und Klassenlehrkraft. --}}
                            @php $wert = (int) ($eingaben['prio'][$key] ?? ($p['wunsch'] || $p['klassenlehrer'] ? 1 : 0)); @endphp
                            <select name="prio[{{ $key }}]" class="rounded-lg border-gray-300 py-1 text-sm">
                                <option value="0" @selected($wert === 0)>–</option>
                                @foreach ($prioText as $n => $t)
                                    <option value="{{ $n }}" @selected($wert === $n)>{{ $n }} · {{ $t }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                @endforeach
            </div>

            <h4 class="mt-5 text-sm font-semibold text-gray-800">2. Wann haben Sie Zeit? <span class="font-normal text-gray-500">(freiwillig)</span></h4>
            <p class="mt-1 text-xs text-gray-500">Ohne Angabe entscheidet der Assistent frei – möglichst an einem Tag und mit wenig Wartezeit. Ein Haken begrenzt auf diesen Tag, Uhrzeiten auf dieses Zeitfenster.</p>
            <div class="mt-2 flex flex-wrap gap-3">
                @foreach ($s->tage->whereIn('id', $tagMitSlots) as $t)
                    @php $e = $eingaben['tage'][$t->id] ?? []; @endphp
                    <div class="flex flex-wrap items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                        <label class="flex items-center gap-2">
                            <input type="hidden" name="tage[{{ $t->id }}][dabei]" value="0">
                            <input type="checkbox" name="tage[{{ $t->id }}][dabei]" value="1" @checked(! empty($e['dabei'])) class="rounded border-gray-300">
                            <span class="font-medium text-gray-800">{{ $t->beschriftung() }}</span>
                        </label>
                        <span class="text-gray-500">von</span>
                        <input type="time" name="tage[{{ $t->id }}][von]" value="{{ $e['von'] ?? '' }}" class="rounded-lg border-gray-300 py-1 text-sm">
                        <span class="text-gray-500">bis</span>
                        <input type="time" name="tage[{{ $t->id }}][bis]" value="{{ $e['bis'] ?? '' }}" class="rounded-lg border-gray-300 py-1 text-sm">
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-sm text-gray-700">Zeit für den Raumwechsel zwischen zwei Gesprächen
                    <select name="abstand" class="rounded-lg border-gray-300 py-1 text-sm">
                        @foreach ([0 => 'keine', 5 => '5 Min.', 10 => '10 Min.', 15 => '15 Min.'] as $m => $t)
                            <option value="{{ $m }}" @selected((int) ($eingaben['abstand'] ?? 0) === $m)>{{ $t }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Vorschlag berechnen</button>
            </div>
        </form>

        @if ($vorschlag)
            @php
                $zeilen = collect($vorschlag['plan']);
                $min = fn ($u) => \Intranet\Modules\Elternsprechtag\Support\Slots::minuten($u);
            @endphp
            <div class="mt-6 rounded-lg border border-indigo-200">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-100 bg-indigo-50 px-4 py-2">
                    <h4 class="font-semibold text-indigo-900">Vorschlag</h4>
                    @foreach ($zeilen->groupBy(fn ($z) => $z['slot']['tag_id']) as $tagZeilen)
                        @php
                            $a = $tagZeilen->min(fn ($z) => $min($z['slot']['von']));
                            $b = $tagZeilen->max(fn ($z) => $min($z['slot']['bis']));
                            $gespraech = $tagZeilen->sum(fn ($z) => $min($z['slot']['bis']) - $min($z['slot']['von']));
                        @endphp
                        <span class="text-sm text-indigo-900">{{ $tagZeilen->first()['slot']['tag'] }}: {{ $tagZeilen->first()['slot']['von'] }}–{{ $tagZeilen->last()['slot']['bis'] }} Uhr,
                            {{ $b - $a - $gespraech > 0 ? ($b - $a - $gespraech).' Min. Wartezeit' : 'ohne Wartezeit' }}</span>
                    @endforeach
                </div>

                @if ($zeilen->isEmpty())
                    <p class="px-4 py-3 text-sm text-gray-600">Leider ließ sich kein Gespräch unterbringen – versuchen Sie es mit einem größeren Zeitfenster.</p>
                @else
                    <div class="divide-y divide-gray-100">
                        @foreach ($zeilen as $i => $z)
                            @php
                                $p = $paare->get($z['key']);
                                $vorher = $zeilen[$i - 1] ?? null;
                                $pause = $vorher && $vorher['slot']['tag_id'] === $z['slot']['tag_id'] ? $min($z['slot']['von']) - $min($vorher['slot']['bis']) : 0;
                                $gebucht = $p && $p['termin'] && (int) $p['termin']->tag_id === (int) $z['slot']['tag_id'] && $p['termin']->von === $z['slot']['von'];
                            @endphp
                            @if ($pause > 0)
                                <div class="bg-gray-50 px-4 py-1 text-xs italic text-gray-500">{{ $pause }} Min. Wartezeit</div>
                            @endif
                            <label class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-2 text-sm {{ $gebucht ? '' : 'cursor-pointer hover:bg-gray-50' }}">
                                @if ($gebucht)
                                    <span class="w-4 text-green-700">✓</span>
                                @else
                                    {{-- Gehört zum Formular „Buchen" unten (form-Attribut), angehakt = wird gebucht. --}}
                                    <input type="checkbox" name="auswahl[]" value="{{ $i }}" form="vorschlag-{{ $s->id }}" checked class="rounded border-gray-300">
                                @endif
                                <span class="w-56 whitespace-nowrap font-medium tabular-nums text-gray-800">{{ $z['slot']['tag'] }} · {{ $z['slot']['von'] }}–{{ $z['slot']['bis'] }}</span>
                                <span class="min-w-48 flex-1">
                                    <span class="font-medium text-gray-800">{{ $p ? $nv($p['plan']->user->name) : '?' }}</span>
                                    <span class="text-gray-500">für {!! $p ? $kn($p['kind']) : '' !!}@if ($p && $p['plan']->raum) · Raum {{ $p['plan']->raum }}@endif</span>
                                </span>
                                <span class="rounded-full px-2 py-0.5 text-xs {{ $prioFarbe[$z['prio']] }}">Prio {{ $z['prio'] }}</span>
                                @if ($gebucht)
                                    <span class="text-sm font-medium text-green-700">gebucht</span>
                                @endif
                            </label>
                        @endforeach
                    </div>
                @endif

                @if ($vorschlag['offen'] !== [])
                    <div class="border-t border-amber-100 bg-amber-50 px-4 py-2 text-sm text-amber-900">
                        Nicht unterzubringen:
                        {!! collect($vorschlag['offen'])->map(fn ($k) => $paare->has($k) ? e($nv($paare[$k]['plan']->user->name)).' (für '.$kn($paare[$k]['kind']).')' : null)->filter()->join(' · ') !!}
                        – dort sind im gewählten Zeitraum keine passenden Uhrzeiten frei.
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 px-4 py-3">
                    @if ($zeilen->isNotEmpty())
                        <form method="POST" action="{{ route('module.elternsprechtag.eltern.vorschlag', [$s] + $als) }}" id="vorschlag-{{ $s->id }}">
                            @csrf
                            <button type="submit" class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Buchen</button>
                        </form>
                        <span class="text-xs text-gray-500">Gebucht werden die angehakten Termine.</span>
                    @endif
                    <form method="POST" action="{{ route('module.elternsprechtag.eltern.assistent', [$s] + $als) }}">
                        @csrf
                        <input type="hidden" name="verwerfen" value="1">
                        <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Vorschlag verwerfen</button>
                    </form>
                </div>
            </div>
        @endif
    @endif
</div>
