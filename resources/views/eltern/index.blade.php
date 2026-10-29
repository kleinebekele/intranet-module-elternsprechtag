@php
    $als = $fremd ? ['eltern' => $eltern->id] : [];
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-800">Sprechtermine</h1>
    </x-slot>

    @if ($verwaltung)
        @include('elternsprechtag::partials.handeln-als', [
            'personen' => $personen, 'param' => 'eltern', 'aktuell' => $eltern,
            'aktion' => route('module.elternsprechtag.eltern.index'),
        ])
    @endif

    @include('elternsprechtag::partials.meldung')

    @forelse ($sprechtage as $eintrag)
        @php
            $s = $eintrag['sprechtag'];
            $offen = \Intranet\Modules\Elternsprechtag\Support\Buchung::offen($s);
        @endphp
        <section class="mb-6 rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-lg font-semibold text-gray-800">{{ $s->titel }}</h2>
                <span class="text-sm text-gray-600">{{ $s->tageText() }}</span>
            </div>
            @if ($s->hinweis)
                <p class="mt-2 whitespace-pre-line text-sm text-gray-600">{{ $s->hinweis }}</p>
            @endif
            @unless ($offen)
                <div class="mt-3 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                    Die Termine sind noch nicht veröffentlicht. Sie erhalten eine Nachricht, sobald es losgeht.
                    @if ($verwaltung)
                        <span class="text-amber-800">(Als Verwaltung kannst du trotzdem buchen.)</span>
                    @endif
                </div>
            @endunless

            @foreach ($eintrag['kinder'] as $z)
                <h3 class="mt-5 text-base font-semibold text-gray-800">
                    {{ $z['kind']->name }} <span class="font-normal text-gray-500">· Klasse {{ implode(', ', $z['klassen']) }}</span>
                </h3>

                @if ($z['lehrer']->isEmpty())
                    <p class="mt-2 text-sm text-gray-400">Für diese Klasse hat noch keine Lehrkraft Gesprächszeiten eingetragen.</p>
                @else
                    <div class="mt-2 grid grid-cols-1 gap-3 md:grid-cols-2 2xl:grid-cols-3">
                        @foreach ($z['lehrer'] as $l)
                            @php
                                $plan = $l['plan'];
                                $termin = $l['termin'];
                                $freie = collect($l['slots'])->where('frei', true);
                                $darfBuchen = $offen || $verwaltung;
                            @endphp
                            <div class="rounded-lg border px-4 py-3 {{ $termin ? 'border-green-300 bg-green-50/60' : 'border-gray-200' }}">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <div class="font-medium text-gray-800">{{ $plan->user->name }}</div>
                                        @if ($plan->raum)
                                            <div class="text-xs text-gray-500">Raum: {{ $plan->raum }}</div>
                                        @endif
                                    </div>
                                    @if ($l['wunsch'] && ! $termin)
                                        <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs text-indigo-800">möchte Sie gern sprechen</span>
                                    @endif
                                </div>

                                @if ($termin)
                                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                                        <span class="font-medium text-green-800">Ihr Termin: {{ $termin->tag?->beschriftung() }}, {{ $termin->von }}–{{ $termin->bis }} Uhr</span>
                                        @if ($darfBuchen)
                                            <form method="POST" action="{{ route('module.elternsprechtag.eltern.absagen', [$termin] + $als) }}"
                                                  data-bestaetigen="Den Termin bei {{ $plan->user->name }} wirklich absagen?"
                                                  data-titel="Termin absagen" data-knopf="Absagen">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="text-xs text-red-700 hover:underline">absagen</button>
                                            </form>
                                        @endif
                                    </div>
                                @endif

                                @if ($darfBuchen)
                                    @if ($freie->isEmpty())
                                        <p class="mt-2 text-sm text-gray-500">{{ $termin ? 'Keine andere Uhrzeit frei.' : 'Leider sind alle Termine vergeben.' }}</p>
                                    @else
                                        <form method="POST" action="{{ route('module.elternsprechtag.eltern.buchen', [$s] + $als) }}"
                                              class="mt-2 flex flex-wrap items-center gap-2">
                                            @csrf
                                            <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                                            <input type="hidden" name="kind_id" value="{{ $z['kind']->id }}">
                                            @include('elternsprechtag::partials.suchauswahl', [
                                                'name' => 'slot',
                                                'optionen' => $freie->map(fn ($sl) => [
                                                    'wert' => $sl['tag_id'].'|'.$sl['von'], 'text' => $sl['von'].'–'.$sl['bis'].' Uhr', 'gruppe' => $sl['tag'],
                                                ])->values()->all(),
                                                'leer' => 'Uhrzeit wählen …',
                                                'breite' => 'w-56',
                                            ])
                                            <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">
                                                {{ $termin ? 'Umbuchen' : 'Buchen' }}
                                            </button>
                                        </form>
                                    @endif
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </section>
    @empty
        <p class="text-sm text-gray-400">
            @if ($fremd)
                Für {{ $eltern->name }} steht kein Elternsprechtag an – oder es ist kein Kind in einer beteiligten Klasse verknüpft.
            @else
                Zurzeit steht für Ihre Kinder kein Elternsprechtag an.
            @endif
        </p>
    @endforelse
</x-app-layout>
