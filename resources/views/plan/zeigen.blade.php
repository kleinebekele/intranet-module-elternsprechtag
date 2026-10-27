@php
    $ziel = $fremd ? [$sprechtag, 'lehrer' => $lehrer->id] : [$sprechtag];
    $start = [
        'slot' => (int) old('slot_minuten', $plan->slot_minuten ?? 10),
        'vorb' => (int) old('vorbereitung_minuten', $plan->vorbereitung_minuten ?? 0),
        'tage' => $sprechtag->tage->mapWithKeys(function ($t) use ($planTage, $plan) {
            $pt = $planTage->get($t->id);
            $alt = old('tage.'.$t->id);

            return [$t->id => $alt !== null ? [
                'dabei' => ! empty($alt['dabei']),
                'von' => $alt['von'] ?? '',
                'bis' => $alt['bis'] ?? '',
                'pausen' => array_values($alt['pausen'] ?? []),
            ] : [
                // Neuer Plan: alle Tage mit den Vorschlagszeiten angehakt.
                'dabei' => $pt !== null || ! $plan->exists,
                'von' => $pt->von ?? $t->standard_von ?? '14:00',
                'bis' => $pt->bis ?? $t->standard_bis ?? '18:00',
                'pausen' => $pt?->pausenListe() ?? [],
            ]];
        }),
    ];
    $gewaehlt = array_map('intval', old('wuensche', $wuensche->keys()->all()));
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold text-gray-800">
                Terminplan · {{ $sprechtag->titel }}@if ($fremd) · <span class="text-indigo-700">{{ $lehrer->name }}</span>@endif
            </h1>
            <a href="{{ $fremd ? route('module.elternsprechtag.sprechtage.show', $sprechtag) : route('module.elternsprechtag.plan.index') }}"
               class="text-sm text-indigo-700 hover:underline">← zurück</a>
        </div>
    </x-slot>

    @include('elternsprechtag::partials.meldung')

    <div class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-1 rounded-xl border border-gray-200 bg-white px-5 py-3 text-sm">
        <span><span class="text-gray-500">Klassen:</span> {{ implode(', ', $klassen) }}</span>
        @if ($sprechtag->lehrer_frist)
            <span class="{{ $sprechtag->fristVorbei() ? 'text-gray-500' : 'font-medium text-indigo-700' }}">
                {{ $sprechtag->fristVorbei() ? 'Frist abgelaufen am' : 'Bitte eintragen bis' }} {{ $sprechtag->lehrer_frist->format('d.m.Y') }}
            </span>
        @endif
        <span>
            @if (! $plan->exists)
                <span class="text-red-600">noch nicht gespeichert</span>
            @elseif ($plan->abgeschlossen_am)
                <span class="text-green-700">✓ als fertig gemeldet am {{ $plan->abgeschlossen_am->format('d.m.Y H:i') }}</span>
            @else
                <span class="text-amber-700">gespeichert, noch nicht als fertig gemeldet</span>
            @endif
        </span>
    </div>

    @unless ($darfAendern)
        <div class="mb-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
            Die Frist ist abgelaufen – der Plan ist nur noch lesbar. Änderungen bitte über die Verwaltung.
        </div>
    @endunless

    <form method="POST" action="{{ route('module.elternsprechtag.plan.speichern', $ziel) }}"
          x-data="terminplan(@js($start))" class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        @csrf
        <fieldset @disabled(! $darfAendern) class="contents">

        {{-- Gesprächslänge, Vorbereitung, Raum --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="text-base font-semibold text-gray-800">Termine</h2>
            <div class="mt-3 grid grid-cols-2 gap-3">
                <label class="block text-sm"><span class="text-gray-600">Länge eines Gesprächs (Min.)</span>
                    <input type="number" name="slot_minuten" min="5" max="120" step="5" x-model.number="slot" required
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                <label class="block text-sm"><span class="text-gray-600">Vorbereitung zwischen Gesprächen (Min.)</span>
                    <input type="number" name="vorbereitung_minuten" min="0" max="60" x-model.number="vorb" required
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
            </div>
            <label class="mt-3 block text-sm"><span class="text-gray-600">Raum</span>
                <input type="text" name="raum" maxlength="255" value="{{ old('raum', $plan->raum) }}" placeholder="z. B. Klassenraum 7a"
                       class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
            <label class="mt-3 block text-sm"><span class="text-gray-600">Bemerkung (für die Verwaltung)</span>
                <textarea name="bemerkung" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300 text-sm">{{ old('bemerkung', $plan->bemerkung) }}</textarea></label>
            <div class="mt-4 rounded-lg bg-indigo-50 px-3 py-2 text-sm text-indigo-900">
                Insgesamt <strong x-text="gesamt()"></strong> Termine
            </div>
        </div>

        {{-- Zeiten je Tag --}}
        <div class="space-y-4 xl:col-span-2">
            @foreach ($sprechtag->tage as $t)
                <div class="rounded-xl border border-gray-200 bg-white p-5" x-data="{ id: {{ $t->id }} }">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label class="flex items-center gap-2">
                            <input type="hidden" name="tage[{{ $t->id }}][dabei]" value="0">
                            <input type="checkbox" name="tage[{{ $t->id }}][dabei]" value="1" x-model="tage[id].dabei" class="rounded border-gray-300">
                            <span class="text-base font-semibold text-gray-800">{{ $t->beschriftung() }}</span>
                        </label>
                        <span class="text-sm text-gray-500" x-show="tage[id].dabei"><span x-text="slots(id).length"></span> Termine</span>
                        <span class="text-sm text-gray-400" x-show="! tage[id].dabei">nicht dabei</span>
                    </div>

                    <div x-show="tage[id].dabei" class="mt-3 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div>
                            <div class="flex flex-wrap items-end gap-2">
                                <label class="block text-sm"><span class="text-gray-600">Beginn</span>
                                    <input type="time" name="tage[{{ $t->id }}][von]" x-model="tage[id].von" class="mt-1 rounded-lg border-gray-300 text-sm"></label>
                                <label class="block text-sm"><span class="text-gray-600">Ende</span>
                                    <input type="time" name="tage[{{ $t->id }}][bis]" x-model="tage[id].bis" class="mt-1 rounded-lg border-gray-300 text-sm"></label>
                            </div>
                            <div class="mt-3 text-sm text-gray-600">Pausen</div>
                            <template x-for="(p, i) in tage[id].pausen" :key="i">
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <input type="time" :name="`tage[${id}][pausen][${i}][von]`" x-model="p.von" class="rounded-lg border-gray-300 text-sm">
                                    <span class="text-gray-400">–</span>
                                    <input type="time" :name="`tage[${id}][pausen][${i}][bis]`" x-model="p.bis" class="rounded-lg border-gray-300 text-sm">
                                    @if ($darfAendern)
                                        <button type="button" @click="tage[id].pausen.splice(i, 1)" class="rounded-lg border border-gray-300 px-2 py-1 text-sm text-gray-600 hover:bg-gray-50" title="Pause entfernen">✕</button>
                                    @endif
                                </div>
                            </template>
                            @if ($darfAendern)
                                <button type="button" @click="tage[id].pausen.push({ von: '', bis: '' })"
                                        class="mt-2 rounded-lg border border-gray-300 px-3 py-1 text-sm text-gray-700 hover:bg-gray-50">+ Pause</button>
                            @endif
                        </div>
                        <div>
                            <div class="text-sm text-gray-600">Ergibt diese Termine</div>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <template x-for="s in slots(id)" :key="s.von">
                                    <span class="rounded bg-gray-100 px-2 py-0.5 text-xs tabular-nums text-gray-700" x-text="`${s.von}–${s.bis}`"></span>
                                </template>
                                <span x-show="slots(id).length === 0" class="text-xs text-amber-700">Keine Termine – Zeiten prüfen.</span>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Gesprächswünsche --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 xl:col-span-3" x-data="{ suche: '' }">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold text-gray-800">Mit diesen Eltern möchte ich unbedingt sprechen</h2>
                    <p class="mt-1 text-sm text-gray-500">Kind anhaken – gemeint sind dessen Eltern. Die Notiz sieht nur die Schule.</p>
                </div>
                <input type="search" x-model="suche" placeholder="Name suchen …" class="rounded-lg border-gray-300 text-sm">
            </div>
            @if ($kinder->isEmpty())
                <p class="mt-3 text-sm text-gray-400">Keine Schüler in {{ implode(', ', $klassen) }} gefunden.</p>
            @else
                @foreach ($kinder->groupBy('klasse') as $klasse => $zeilen)
                    <h3 class="mt-4 text-sm font-semibold text-gray-700">Klasse {{ $klasse }}</h3>
                    <div class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-2 2xl:grid-cols-3">
                        @foreach ($zeilen as $z)
                            @php
                                $kid = $z['kind']->id;
                                $an = in_array($kid, $gewaehlt, true);
                                $elternNamen = $z['eltern']->pluck('name')->join(', ');
                            @endphp
                            <div x-data="{ an: @js($an) }"
                                 x-show="suche === '' || @js(mb_strtolower($z['kind']->name.' '.$elternNamen)).includes(suche.toLowerCase())"
                                 class="rounded-lg border px-3 py-2" :class="an ? 'border-indigo-300 bg-indigo-50/60' : 'border-gray-200'">
                                <label class="flex items-start gap-2">
                                    <input type="checkbox" name="wuensche[]" value="{{ $kid }}" x-model="an" class="mt-0.5 rounded border-gray-300">
                                    <span class="text-sm">
                                        <span class="font-medium text-gray-800">{{ $z['kind']->name }}</span>
                                        <span class="block text-xs {{ $elternNamen === '' ? 'text-amber-700' : 'text-gray-500' }}">
                                            {{ $elternNamen === '' ? 'keine Eltern verknüpft' : 'Eltern: '.$elternNamen }}
                                        </span>
                                    </span>
                                </label>
                                <input type="text" name="notiz[{{ $kid }}]" x-show="an" maxlength="255" placeholder="Notiz (optional)"
                                       value="{{ old('notiz.'.$kid, $wuensche->get($kid)?->notiz) }}"
                                       class="mt-2 w-full rounded-lg border-gray-300 text-sm">
                            </div>
                        @endforeach
                    </div>
                @endforeach
            @endif
        </div>
        </fieldset>

        @if ($darfAendern)
            <div class="flex flex-wrap gap-2 xl:col-span-3">
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Speichern</button>
            </div>
        @endif
    </form>

    @if ($darfAendern && $plan->exists)
        <form method="POST" action="{{ route('module.elternsprechtag.plan.abschliessen', $ziel) }}" class="mt-2">
            @csrf
            <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                {{ $plan->abgeschlossen_am ? 'Wieder öffnen' : 'Fertig – Plan an die Verwaltung melden' }}
            </button>
        </form>
    @endif

    <script>
        function terminplan(start) {
            const min = (u) => { const m = /^(\d{1,2}):(\d{2})$/.exec(u || ''); return m ? (+m[1]) * 60 + (+m[2]) : null; };
            const uhr = (n) => String(Math.floor(n / 60)).padStart(2, '0') + ':' + String(n % 60).padStart(2, '0');

            return {
                slot: start.slot,
                vorb: start.vorb,
                tage: start.tage,
                // Gleiche Regel wie Support\Slots: Pause schiebt das Gespräch hinter sich.
                slots(id) {
                    const t = this.tage[id];
                    const a = min(t.von), e = min(t.bis), d = +this.slot || 0, v = Math.max(0, +this.vorb || 0);
                    if (! t.dabei || a === null || e === null || e <= a || d < 1) return [];
                    const pausen = t.pausen.map(p => [min(p.von), min(p.bis)]).filter(([x, y]) => x !== null && y !== null && y > x);
                    const liste = [];
                    let z = a;
                    while (z + d <= e && liste.length < 500) {
                        const p = pausen.find(([x, y]) => z < y && z + d > x);
                        if (p) { z = p[1]; continue; }
                        liste.push({ von: uhr(z), bis: uhr(z + d) });
                        z += d + v;
                    }
                    return liste;
                },
                gesamt() {
                    return Object.keys(this.tage).reduce((n, id) => n + this.slots(id).length, 0);
                },
            };
        }
    </script>
</x-app-layout>
