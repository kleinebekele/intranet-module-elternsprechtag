@php
    $nv = fn ($n) => \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($n);
    $ziel = $fremd ? [$sprechtag, 'lehrer' => $lehrer->id] : [$sprechtag];
    $start = [
        'slot' => (int) old('slot_minuten', $plan->slot_minuten ?? 15),
        'vorb' => (int) old('vorbereitung_minuten', $plan->vorbereitung_minuten ?? 0),
        'klassen' => array_map('strval', old('klassen', $klassen)),
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
                'von' => $pt->von ?? $t->standard_von ?? '08:00',
                'bis' => $pt->bis ?? $t->standard_bis ?? '15:00',
                'pausen' => $pt?->pausenListe() ?? [],
            ]];
        }),
        // Gebuchte Slots je Tag: von → wer gebucht hat (Tooltip) und ob es Wunsch-Eltern sind (Farbe).
        'gebucht' => $plan->termine->groupBy('tag_id')->map(fn ($ts) => $ts->mapWithKeys(fn ($t) => [
            $t->von => [
                'kind' => $nv($t->kind?->name),
                'eltern' => $t->eltern ? $nv($t->eltern->name) : 'Eltern',
                'zusatz' => trim(($t->gruppe ? 'gemeinsam mit '.($team['partnerVon'][$t->gruppe] ?? '') : '')
                    .($t->allein ? 'nur bei '.($fremd ? $nv($lehrer->name) : 'dir') : '')),
                'wunsch' => $wuensche->has($t->kind_user_id),
            ],
        ])),
    ];
    $gewaehlt = array_map('intval', old('wuensche', $wuensche->keys()->all()));
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold text-gray-800">
                Terminplan · {{ $sprechtag->titel }}@if ($fremd) · <span class="text-indigo-700">{{ $lehrer->name }}</span>@endif
            </h1>
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ $fremd ? route('module.elternsprechtag.sprechtage.show', $sprechtag) : route('module.elternsprechtag.plan.index') }}"
                   class="text-sm text-indigo-700 hover:underline">← zurück</a>
            </div>
        </div>
    </x-slot>

    @if ($verwaltung)
        @include('elternsprechtag::partials.handeln-als', [
            'personen' => $personen, 'param' => 'lehrer', 'aktuell' => $lehrer,
            'aktion' => route('module.elternsprechtag.plan.zeigen', $sprechtag),
        ])
    @endif

    @include('elternsprechtag::partials.meldung')

    {{-- Gebuchte Termine: öffnet sich über „(N gebucht)" bei „Insgesamt … Termine". Außerhalb des Formulars, weil es eigene Absage-Formulare enthält. --}}
    @if ($plan->termine->isNotEmpty())
        @php
            $gueltig = [];
            foreach ($plan->tage as $pt) {
                foreach (\Intranet\Modules\Elternsprechtag\Support\Slots::fuer($plan, $pt) as $sl) {
                    $gueltig[] = $pt->tag_id.'|'.$sl['von'];
                }
            }
        @endphp
        <div x-data="{ offen: false }" @termine-zeigen.window="offen = true" @keydown.escape.window="offen = false">
            <div x-show="offen" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:items-center"
                 @click.self="offen = false">
                <div class="w-full max-w-2xl rounded-xl bg-white p-5 shadow-xl">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-base font-semibold text-gray-800">Gebuchte Termine ({{ $plan->termine->count() }})</h2>
                        <button type="button" @click="offen = false" class="text-2xl leading-none text-gray-400 hover:text-gray-600" title="Schließen">×</button>
                    </div>
                    <div class="mt-3 divide-y divide-gray-100 text-sm">
                        @foreach ($plan->termine->sortBy(fn ($t) => $t->tag?->datum?->format('Y-m-d').' '.$t->von) as $t)
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2">
                                <span class="font-medium tabular-nums text-gray-800">{{ $t->tag?->beschriftung() }} · {{ $t->von }}–{{ $t->bis }}</span>
                                <span>{{ $nv($t->kind?->name) }}</span>
                                <span class="text-gray-500">({{ $t->eltern ? $nv($t->eltern->name) : 'Eltern' }})</span>
                                @if ($t->gruppe)
                                    <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs text-indigo-800">gemeinsam mit {{ $team['partnerVon'][$t->gruppe] ?? '' }}</span>
                                @elseif ($t->allein)
                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-800">nur hier – gemeinsam war nichts mehr frei</span>
                                @endif
                                @unless (in_array($t->tag_id.'|'.$t->von, $gueltig, true))
                                    <span class="text-amber-700">passt nicht mehr zu den Zeiten</span>
                                @endunless
                                @if ($verwaltung || $t->beginn()->isFuture())
                                    <form method="POST" action="{{ route('module.elternsprechtag.plan.absagen', $fremd ? [$t, 'lehrer' => $lehrer->id] : [$t]) }}" class="ml-auto"
                                          data-bestaetigen="Termin {{ $t->tag?->beschriftung() }}, {{ $t->von }} Uhr mit den Eltern von {{ $t->kind?->name }} absagen? Die Eltern bekommen eine Mail."
                                          data-titel="Termin absagen" data-knopf="Absagen">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs text-red-700 hover:underline">absagen</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4 flex justify-end">
                        <a href="{{ route('module.elternsprechtag.plan.pdf', $ziel) }}"
                           class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Terminliste als PDF</a>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Terminliste ansehen (Auge): derselbe Inhalt wie das PDF, erst beim Öffnen geladen. --}}
    @if ($plan->exists)
        <div x-data="{ offen: false, src: '' }" @liste-ansehen.window="offen = true; src = @js(route('module.elternsprechtag.plan.ansicht', $ziel))" @keydown.escape.window="offen = false">
            <div x-show="offen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="offen = false">
                <div class="flex h-[90vh] w-full max-w-4xl flex-col rounded-xl bg-white shadow-xl">
                    <div class="flex items-center justify-between gap-2 border-b border-gray-200 px-5 py-3">
                        <h2 class="text-base font-semibold text-gray-800">Terminliste</h2>
                        <div class="flex items-center gap-3">
                            <a href="{{ route('module.elternsprechtag.plan.pdf', $ziel) }}" class="inline-flex items-center gap-1 text-sm text-red-600 hover:text-red-700"><i class="bx bxs-file-pdf text-xl"></i> PDF</a>
                            <button type="button" @click="offen = false" class="text-2xl leading-none text-gray-400 hover:text-gray-600" title="Schließen">×</button>
                        </div>
                    </div>
                    <iframe :src="src" class="w-full flex-1 rounded-b-xl bg-white" title="Terminliste"></iframe>
                </div>
            </div>
        </div>
    @endif

    <div class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-1 rounded-xl border border-gray-200 bg-white px-5 py-3 text-sm">
        <span><span class="text-gray-500">Klassen des Sprechtags:</span> {{ implode(', ', $sprechtag->klassenListe()) }}</span>
        @if ($sprechtag->lehrer_frist)
            <span class="{{ $sprechtag->fristVorbei() ? 'text-gray-500' : 'font-medium text-indigo-700' }}">
                {{ $sprechtag->fristVorbei() ? 'Frist abgelaufen am' : 'Bitte eintragen bis' }} {{ $sprechtag->lehrer_frist->format('d.m.Y') }}
            </span>
        @endif
        <span>
            @if (! $plan->exists)
                <span class="text-red-600">noch nicht gespeichert</span>
            @elseif ($plan->abgesagt())
                <span class="text-gray-700">✗ Teilnahme abgesagt</span>
            @elseif ($plan->abgeschlossen_am)
                <span class="text-green-700">✓ freigegeben am {{ $plan->abgeschlossen_am->format('d.m.Y H:i') }}</span>
            @else
                <span class="text-amber-700">gespeichert, noch nicht freigegeben</span>
            @endif
        </span>
    </div>

    {{-- Gemeinsam gedachte Gespräche, die nur bei einer Klassenlehrkraft frei waren – Hinweis für beide. --}}
    @php $allein = $plan->termine->where('allein', true); @endphp
    @if ($allein->isNotEmpty() || $team['beiPartner']->isNotEmpty())
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <div class="font-semibold">Gemeinsame Gespräche, die nur bei einer Klassenlehrkraft möglich waren</div>
            <p class="mt-0.5 text-xs">Es war keine Uhrzeit mehr frei, zu der alle Klassenlehrkräfte konnten – die Eltern haben deshalb bei einer gebucht.</p>
            <ul class="mt-2 space-y-0.5">
                @foreach ($allein->sortBy(fn ($t) => $t->tag?->datum?->format('Y-m-d').' '.$t->von) as $t)
                    <li>{{ $t->tag?->beschriftung() }}, {{ $t->von }} Uhr · {{ $nv($t->kind?->name) }} – <strong>bei {{ $fremd ? $nv($lehrer->name) : 'dir' }}</strong></li>
                @endforeach
                @foreach ($team['beiPartner'] as $t)
                    <li>{{ $t->tag?->beschriftung() }}, {{ $t->von }} Uhr · {{ $nv($t->kind?->name) }} – <strong>nur bei {{ $nv($t->plan?->user?->name) }}</strong></li>
                @endforeach
            </ul>
        </div>
    @endif

    @unless ($darfAendern)
        <div class="mb-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
            {{ $sprechtag->veroeffentlicht() ? 'Die Termine sind für die Eltern veröffentlicht' : 'Die Frist ist abgelaufen' }} – der Plan ist nur noch lesbar. Änderungen bitte über die Verwaltung.
        </div>
    @endunless

    @if ($plan->abgesagt())
        <div class="rounded-xl border border-gray-300 bg-gray-50 p-5 text-sm text-gray-700">
            <div class="font-semibold text-gray-800">{{ $fremd ? $lehrer->name.' nimmt' : 'Du nimmst' }} an diesem Elternsprechtag nicht teil.</div>
            <div class="mt-1">Abgesagt am {{ $plan->abgesagt_am->format('d.m.Y H:i') }}@if ($plan->absage_grund) · Grund: {{ $plan->absage_grund }}@endif</div>
            @if ($darfAendern)
                <form method="POST" action="{{ route('module.elternsprechtag.plan.teilnahme', $ziel) }}" class="mt-3">
                    @csrf
                    <input type="hidden" name="absagen" value="0">
                    <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Doch teilnehmen</button>
                </form>
            @endif
        </div>
    @else
    <form method="POST" action="{{ route('module.elternsprechtag.plan.speichern', $ziel) }}"
          x-data="terminplan(@js($start))" class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        @csrf
        <fieldset @disabled(! $darfAendern) class="contents">

        {{-- Gesprächslänge, Vorbereitung, Raum --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="text-base font-semibold text-gray-800">Ich stehe zur Verfügung für</h2>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($sprechtag->klassenListe() as $k)
                    <label class="flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-sm"
                           :class="klassen.includes(@js((string) $k)) ? 'border-indigo-300 bg-indigo-50/60' : 'border-gray-200'">
                        <input type="checkbox" name="klassen[]" value="{{ $k }}" x-model="klassen" class="rounded border-gray-300">
                        {{ $k }}
                    </label>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-gray-500">
                @if ($linearKlassen !== [])
                    Laut Linear {{ $fremd ? 'unterrichtet '.$lehrer->name : 'unterrichtest du' }} in {{ implode(', ', $linearKlassen) }} – das ist nur die Vorauswahl.
                @else
                    Laut Linear {{ $fremd ? 'unterrichtet '.$lehrer->name : 'unterrichtest du' }} in keiner dieser Klassen.
                @endif
            </p>

            <h2 class="mt-5 text-base font-semibold text-gray-800">Termine (in Minuten)</h2>
            <div class="mt-3 grid grid-cols-2 gap-3">
                <label class="block text-sm"><span class="text-gray-600">Gesprächsdauer</span>
                    <input type="number" name="slot_minuten" min="5" max="120" step="5" x-model.number="slot" required
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                <label class="block text-sm"><span class="text-gray-600">Vorbereitungszeit</span>
                    <input type="number" name="vorbereitung_minuten" min="0" max="60" x-model.number="vorb" required
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
            </div>
            @foreach ($team['klassen'] as $klasse => $tk)
                <div class="mt-3 rounded-lg border border-indigo-200 bg-indigo-50/50 p-3 text-sm">
                    <div class="font-semibold text-indigo-900">Gemeinsame Gespräche · Klasse {{ $klasse }}</div>
                    <div class="text-gray-700">mit {{ $tk['partner']->map(fn ($u) => $nv($u->name))->join(' & ') }}</div>
                    <div class="mt-2 space-y-1">
                        @foreach (\Intranet\Modules\Elternsprechtag\Support\Team::MODI as $wert => $text)
                            <label class="flex items-center gap-2">
                                <input type="radio" name="team[{{ $klasse }}]" value="{{ $wert }}" @checked(old('team.'.$klasse, $tk['modus']) === $wert) class="border-gray-300">
                                {{ $text }}
                            </label>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-gray-600">Gesprächsdauer und Vorbereitungszeit gelten für alle Klassenlehrkräfte der Klasse und werden beim Speichern übernommen. Beginn, Ende und Pausen bleiben eigene Sache – gemeinsam buchbar sind die Uhrzeiten, die bei allen gleich liegen.</p>
                    <p class="mt-1 text-xs">
                        @if ($tk['ohnePlan']->isNotEmpty())
                            <span class="text-amber-700">{{ $tk['ohnePlan']->map(fn ($u) => $nv($u->name))->join(', ') }} {{ $tk['ohnePlan']->count() === 1 ? 'hat' : 'haben' }} noch keinen Plan.</span>
                        @elseif ($tk['gemeinsam'] !== null)
                            <span class="{{ $tk['gemeinsam'] > 0 ? 'text-gray-700' : 'text-amber-700' }}">Gemeinsame Uhrzeiten (gespeicherter Stand): <strong>{{ $tk['gemeinsam'] }}</strong></span>
                        @endif
                    </p>
                </div>
            @endforeach
            <label class="mt-3 block text-sm"><span class="text-gray-600">Raum</span>
                <input type="text" name="raum" maxlength="255" value="{{ old('raum', $plan->raum) }}" placeholder="z. B. Klassenraum 7a"
                       class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
            <label class="mt-3 block text-sm"><span class="text-gray-600">Bemerkung (für die Verwaltung)</span>
                <textarea name="bemerkung" rows="3" maxlength="2000" class="mt-1 w-full rounded-lg border-gray-300 text-sm">{{ old('bemerkung', $plan->bemerkung) }}</textarea></label>
        </div>

        {{-- Zeiten je Tag --}}
        <div class="space-y-4 xl:col-span-2">
            {{-- Legende und Gesamtzahl. Links statt Buttons: ein deaktiviertes fieldset sperrt sonst auch das Öffnen. --}}
            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 rounded-xl border border-gray-200 bg-white px-5 py-3 text-sm">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600">
                    <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-6 rounded bg-gray-100 ring-1 ring-gray-200"></span> frei</span>
                    <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-6 rounded bg-green-100 ring-1 ring-green-300"></span> gebucht</span>
                    <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-6 rounded bg-violet-200 ring-1 ring-violet-400"></span> gebucht von Wunsch-Eltern ★</span>
                    <span class="text-gray-400">Maus auf einen Termin: wer gebucht hat</span>
                </div>
                <div class="ml-auto flex items-center gap-2 text-gray-800">
                    <span>Insgesamt <strong x-text="gesamt()"></strong> Termine</span>
                    @if ($plan->termine->isNotEmpty())
                        <a href="#" @click.prevent="$dispatch('termine-zeigen')" class="font-medium text-green-700 underline hover:text-green-800">({{ $plan->termine->count() }} gebucht)</a>
                    @endif
                    @if ($plan->exists)
                        <a href="{{ route('module.elternsprechtag.plan.pdf', $ziel) }}" title="Terminliste als PDF herunterladen"
                           class="inline-flex items-center text-2xl leading-none text-red-600 hover:text-red-700"><i class="bx bxs-file-pdf"></i></a>
                        <a href="#" @click.prevent="$dispatch('liste-ansehen')" title="Terminliste ansehen (wie im PDF)"
                           class="inline-flex items-center text-2xl leading-none text-gray-500 hover:text-indigo-700"><i class="bx bx-show"></i></a>
                    @endif
                </div>
            </div>

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
                                    <span class="group relative rounded px-2 py-0.5 text-xs tabular-nums" tabindex="0"
                                          :class="farbe(gebucht(id, s.von))">
                                        <span x-text="`${s.von}–${s.bis}`"></span><span x-show="gebucht(id, s.von)?.wunsch"> ★</span>
                                        {{-- Wer gebucht hat: sofort beim Überfahren (bzw. Antippen), nicht erst nach der Verzögerung von title. --}}
                                        <template x-if="gebucht(id, s.von)">
                                            <span class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1.5 hidden w-max max-w-64 -translate-x-1/2 rounded-lg bg-gray-900 px-2.5 py-1.5 text-left text-xs font-normal text-white shadow-lg group-hover:block group-focus:block">
                                                <span class="block font-semibold" x-text="gebucht(id, s.von).kind"></span>
                                                <span class="block" x-text="'Eltern: ' + gebucht(id, s.von).eltern"></span>
                                                <span class="block text-violet-200" x-show="gebucht(id, s.von).wunsch">★ Wunschgespräch</span>
                                                <span class="block text-gray-300" x-show="gebucht(id, s.von).zusatz" x-text="gebucht(id, s.von).zusatz"></span>
                                            </span>
                                        </template>
                                    </span>
                                </template>
                                <span x-show="slots(id).length === 0" class="text-xs text-amber-700">Keine Termine – Zeiten prüfen.</span>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Gesprächswünsche --}}
        {{-- Aufklapper als div statt button: ein deaktiviertes fieldset sperrt sonst auch das Aufklappen. --}}
        <div class="rounded-xl border border-gray-200 bg-white p-5 xl:col-span-3"
             x-data="{ offen: false, suche: '', anzahl: {{ count($gewaehlt) }} }">
            <div role="button" tabindex="0" :aria-expanded="offen" @click="offen = ! offen" @keydown.enter.prevent="offen = ! offen" @keydown.space.prevent="offen = ! offen"
                 class="flex cursor-pointer items-center justify-between gap-2">
                <div>
                    <h2 class="text-base font-semibold text-gray-800">Mit diesen Eltern möchte ich unbedingt sprechen</h2>
                    <p class="mt-1 text-sm text-gray-500"><span x-text="anzahl"></span> ausgewählt</p>
                </div>
                <i class="bx bx-chevron-down text-2xl text-gray-500 transition-transform" :class="offen && 'rotate-180'"></i>
            </div>
            <div x-show="offen" x-cloak>
            <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-gray-500">Kind anhaken – gemeint sind dessen Eltern. Die Notiz sieht nur die Schule.</p>
                <input type="search" x-model="suche" placeholder="Name suchen …" class="rounded-lg border-gray-300 text-sm">
            </div>
            @if ($kinder->isEmpty())
                <p class="mt-3 text-sm text-gray-400">Keine Schüler gefunden.</p>
            @else
                <p x-show="klassen.length === 0" class="mt-3 text-sm text-gray-400">Oben zuerst die Klassen wählen.</p>
                @foreach ($kinder->groupBy('klasse') as $klasse => $zeilen)
                    <div x-show="klassen.includes(@js((string) $klasse))">
                    <h3 class="mt-4 text-sm font-semibold text-gray-700">Klasse {{ $klasse }}</h3>
                    <div class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-2 2xl:grid-cols-3">
                        @foreach ($zeilen as $z)
                            @php
                                $kid = $z['kind']->id;
                                $an = in_array($kid, $gewaehlt, true);
                                $elternNamen = $z['eltern']->map(fn ($e) => $nv($e->name))->join(' · ');
                            @endphp
                            <div x-data="{ an: @js($an) }"
                                 x-show="suche === '' || @js(mb_strtolower($z['kind']->name.' '.$nv($z['kind']->name).' '.$elternNamen)).includes(suche.toLowerCase())"
                                 class="rounded-lg border px-3 py-2" :class="an ? 'border-indigo-300 bg-indigo-50/60' : 'border-gray-200'">
                                <label class="flex items-start gap-2">
                                    <input type="checkbox" name="wuensche[]" value="{{ $kid }}" x-model="an" @change="anzahl += $event.target.checked ? 1 : -1" class="mt-0.5 rounded border-gray-300">
                                    <span class="text-sm">
                                        <span class="font-medium text-gray-800">{{ $nv($z['kind']->name) }}</span>
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
                    </div>
                @endforeach
            @endif
            </div>
        </div>
        </fieldset>

        @if ($darfAendern)
            <div class="flex flex-wrap gap-2 xl:col-span-3">
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Speichern</button>
                @unless ($plan->abgeschlossen_am)
                    <button type="submit" name="freigeben" value="1"
                            class="rounded-lg bg-green-600 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Speichern und freigeben</button>
                @endunless
                {{-- Nach der Freigabe erst die Freigabe zurücknehmen, dann absagen. --}}
                <button type="button" @click="$dispatch('teilnahme-absagen')" @disabled($plan->abgeschlossen_am)
                        @if ($plan->abgeschlossen_am) title="Erst die Freigabe zurücknehmen, dann absagen." @endif
                        class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm text-red-700 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50">Teilnahme absagen</button>
            </div>
        @endif
    </form>

    @if ($darfAendern && $plan->abgeschlossen_am)
        <form method="POST" action="{{ route('module.elternsprechtag.plan.abschliessen', $ziel) }}" class="mt-2"
              data-bestaetigen="Der Plan gilt dann für die Verwaltung wieder als in Arbeit."
              data-titel="Freigabe zurücknehmen" data-knopf="Zurücknehmen">
            @csrf
            <button type="submit" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Freigabe zurücknehmen</button>
        </form>
    @endif

    {{-- Teilnahme absagen: öffnet sich über den Knopf neben „Speichern". Eigenes Formular, daher außerhalb des Plan-Formulars. --}}
    @if ($darfAendern)
        <div x-data="{ offen: false }" @teilnahme-absagen.window="offen = true; $nextTick(() => $refs.grund.focus())" @keydown.escape.window="offen = false">
            <div x-show="offen" x-cloak class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:items-center"
                 @click.self="offen = false">
                <form method="POST" action="{{ route('module.elternsprechtag.plan.teilnahme', $ziel) }}" class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
                    @csrf
                    <input type="hidden" name="absagen" value="1">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-base font-semibold text-gray-800">Teilnahme absagen</h2>
                        <button type="button" @click="offen = false" class="text-2xl leading-none text-gray-400 hover:text-gray-600" title="Schließen">×</button>
                    </div>
                    <p class="mt-2 text-sm text-gray-600">
                        {{ $fremd ? $lehrer->name.' nimmt' : 'Du nimmst' }} an diesem Elternsprechtag nicht teil. Die Eltern sehen {{ $fremd ? 'die Lehrkraft' : 'dich' }} dann nicht mehr.
                        @if ($plan->termine->isNotEmpty())
                            <strong class="text-red-700">{{ $plan->termine->count() === 1 ? 'Der gebuchte Termin wird' : 'Die '.$plan->termine->count().' gebuchten Termine werden' }} abgesagt, die Eltern bekommen eine Mail.</strong>
                        @endif
                    </p>
                    <label class="mt-4 block text-sm"><span class="text-gray-600">Grund (für das Sekretariat, optional)</span>
                        <textarea name="grund" rows="3" maxlength="500" x-ref="grund" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></textarea></label>
                    <div class="mt-4 flex justify-end gap-2">
                        <button type="button" @click="offen = false" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Abbrechen</button>
                        <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">Teilnahme absagen</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
    @endif

    <script>
        function terminplan(start) {
            const min = (u) => { const m = /^(\d{1,2}):(\d{2})$/.exec(u || ''); return m ? (+m[1]) * 60 + (+m[2]) : null; };
            const uhr = (n) => String(Math.floor(n / 60)).padStart(2, '0') + ':' + String(n % 60).padStart(2, '0');

            return {
                slot: start.slot,
                vorb: start.vorb,
                klassen: start.klassen,
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
                gebucht(id, von) {
                    return (start.gebucht[id] || {})[von] || null;
                },
                farbe(g) {
                    if (! g) return 'bg-gray-100 text-gray-700';
                    return g.wunsch
                        ? 'cursor-default bg-violet-200 font-semibold text-violet-900 ring-1 ring-violet-400'
                        : 'cursor-default bg-green-100 font-medium text-green-800 ring-1 ring-green-300';
                },
                gesamt() {
                    return Object.keys(this.tage).reduce((n, id) => n + this.slots(id).length, 0);
                },
            };
        }
    </script>
</x-app-layout>
