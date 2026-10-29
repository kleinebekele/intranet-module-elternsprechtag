<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold text-gray-800">{{ $sprechtag->titel }}</h1>
            <div class="flex flex-wrap gap-2">
                @darfRoute('module.elternsprechtag.sprechtage.edit')
                    <a href="{{ route('module.elternsprechtag.sprechtage.edit', $sprechtag) }}"
                       class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Bearbeiten</a>
                @enddarfRoute
                @darfRoute('module.elternsprechtag.sprechtage.destroy')
                    <form method="POST" action="{{ route('module.elternsprechtag.sprechtage.destroy', $sprechtag) }}"
                          data-bestaetigen="Elternsprechtag „{{ $sprechtag->titel }}“ samt allen Terminplänen der Lehrer löschen?"
                          data-titel="Elternsprechtag löschen" data-knopf="Löschen">
                        @csrf @method('DELETE')
                        <button type="submit" class="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-700 hover:bg-red-50">Löschen</button>
                    </form>
                @enddarfRoute
            </div>
        </div>
    </x-slot>

    @include('elternsprechtag::partials.meldung')

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="text-base font-semibold text-gray-800">Eckdaten</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div><dt class="text-gray-500">Tage</dt>
                    <dd>
                        @forelse ($sprechtag->tage as $t)
                            <div>{{ $t->beschriftung() }}@if ($t->standard_von) · {{ $t->standard_von }}–{{ $t->standard_bis }} Uhr @endif</div>
                        @empty
                            –
                        @endforelse
                    </dd></div>
                <div><dt class="text-gray-500">Klassen</dt><dd>{{ implode(', ', $sprechtag->klassenListe()) ?: '–' }}</dd></div>
                <div><dt class="text-gray-500">Frist für die Lehrer</dt>
                    <dd>{{ $sprechtag->lehrer_frist?->format('d.m.Y') ?? 'keine' }}
                        @if ($sprechtag->fristVorbei()) <span class="text-xs text-gray-500">(abgelaufen – Lehrer können nichts mehr ändern)</span> @endif</dd></div>
            </dl>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 xl:col-span-2">
            <h2 class="text-base font-semibold text-gray-800">Veröffentlichen</h2>
            @php
                $offeneLehrer = $lehrer->filter(fn ($z) => ! ($pl = $plaene->get($z['user']->id)) || (! $pl->freigegeben() && ! $pl->abgesagt()))->count();
            @endphp
            <p class="mt-1 text-sm text-gray-500">
                Gibt die Termine aller Lehrer für die Eltern zur Buchung frei – danach können Lehrer ihre Pläne nicht mehr ändern.
                Dazu geht eine Mail an alle Eltern der beteiligten Klassen ({{ $eltern->count() }} Personen,
                {{ $eltern->reject(fn ($u) => $u->istGesperrt() || blank($u->email))->count() }} mit Adresse und nicht gesperrt).
                Den Wortlaut gibt es unter Verwaltung → Mailvorlagen („Elternsprechtag: Info an die Eltern"); der Hinweistext vom Sprechtag wird eingesetzt.
            </p>
            @if ($sprechtag->hinweis)
                <div class="mt-3 whitespace-pre-line rounded-lg bg-gray-50 p-3 text-sm text-gray-700">{{ $sprechtag->hinweis }}</div>
            @endif
            <div class="mt-3 flex flex-wrap items-center gap-3">
                @darfRoute('module.elternsprechtag.sprechtage.info')
                    <form method="POST" action="{{ route('module.elternsprechtag.sprechtage.info', $sprechtag) }}"
                          data-bestaetigen="{{ $sprechtag->info_versendet_am ? 'Die Info ging schon am '.$sprechtag->info_versendet_am->format('d.m.Y').' raus. Wirklich NOCHMAL an '.$eltern->count().' Eltern verschicken?' : 'Termine veröffentlichen und '.$eltern->count().' Eltern informieren?'.($offeneLehrer > 0 ? ' '.$offeneLehrer.' Lehrer haben ihren Plan noch nicht freigegeben.' : '').' Danach können Lehrer nichts mehr ändern.' }}"
                          data-titel="Veröffentlichen" data-knopf="Veröffentlichen">
                        @csrf
                        <button type="submit" @disabled($eltern->isEmpty() || $sprechtag->tage->isEmpty())
                                class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">
                            {{ $sprechtag->info_versendet_am ? 'Info erneut senden' : 'Veröffentlichen' }}
                        </button>
                    </form>
                @enddarfRoute
                @if ($sprechtag->info_versendet_am)
                    <span class="text-sm text-green-700">✓ veröffentlicht am {{ $sprechtag->info_versendet_am->format('d.m.Y H:i') }}, Info an {{ $sprechtag->info_empfaenger }} Eltern</span>
                @endif
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 xl:col-span-3">
            <h2 class="text-base font-semibold text-gray-800">Lehrer ({{ $lehrer->count() }})</h2>
            <p class="mt-1 text-sm text-gray-500">Lehrer laut Linear und alle, die selbst einen Plan angelegt haben. Klick auf den Namen öffnet den Terminplan.</p>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="py-2 pr-4">Lehrer</th>
                            <th class="py-2 pr-4">Klassen</th>
                            <th class="py-2 pr-4">Stand</th>
                            <th class="py-2 pr-4">Tage</th>
                            <th class="py-2 pr-4">Gebucht / Termine</th>
                            <th class="py-2 pr-4">Gesprächswünsche</th>
                            <th class="py-2 pr-4">Raum</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($lehrer as $z)
                            @php
                                $plan = $plaene->get($z['user']->id);
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="py-2 pr-4">
                                    <a href="{{ route('module.elternsprechtag.plan.zeigen', [$sprechtag, 'lehrer' => $z['user']->id]) }}"
                                       class="font-medium text-indigo-700 hover:underline">{{ \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($z['user']->name) }}</a>
                                </td>
                                <td class="py-2 pr-4">{{ implode(', ', $z['klassen']) ?: 'keine' }}</td>
                                <td class="py-2 pr-4">
                                    @if (! $plan)
                                        <span class="text-red-600">noch nichts eingetragen</span>
                                    @elseif ($plan->abgesagt())
                                        <span class="text-gray-700">✗ nimmt nicht teil</span>
                                        @if ($plan->absage_grund)
                                            <div class="text-xs text-gray-500">{{ $plan->absage_grund }}</div>
                                        @endif
                                    @elseif ($plan->abgeschlossen_am)
                                        <span class="text-green-700">✓ freigegeben</span>
                                    @else
                                        <span class="text-amber-700">nicht freigegeben</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $plan?->tage->count() ?? '–' }}</td>
                                <td class="py-2 pr-4">{{ $plan ? $plan->termine->count().' / '.$plan->anzahlTermine() : '–' }}</td>
                                <td class="py-2 pr-4">{{ $plan?->wuensche->count() ?? '–' }}</td>
                                <td class="py-2 pr-4">{{ $plan?->raum ?: '–' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-3 text-gray-400">Keine Lehrer – Klassen wählen oder Linear-Abgleich prüfen.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
