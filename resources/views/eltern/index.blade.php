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
            $offen = \Intranet\Modules\Elternsprechtag\Support\Buchung::offen($s, $eltern);
            $mitReitern = $offen || $verwaltung;
            // Start ist der Assistent; wer gerade von Hand gebucht oder abgesagt hat, bleibt auf „Manuelle Termine".
            $start = ! $mitReitern || session('elternsprechtag_reiter') === $s->id || old('plan_id') ? 'manuell' : 'assistent';
            $reiterKlasse = 'whitespace-nowrap border-b-2 -mb-px px-4 py-2 text-sm font-medium transition';
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
                    @if ($s->veroeffentlicht() && $s->buchungGeschlossen())
                        Die Terminbuchung ist seit {{ $s->buchungsschluss()->format('d.m.Y, H:i') }} Uhr geschlossen. Ihre gebuchten Termine stehen unten. Wenn Sie einen Termin nicht wahrnehmen können, geben Sie bitte der Lehrkraft direkt Bescheid.
                    @elseif ($s->veroeffentlicht())
                        Zurzeit buchen nur Eltern, die eine Lehrkraft persönlich eingeladen hat. Sie erhalten eine Nachricht, sobald die Buchung für alle öffnet.
                    @else
                        Die Termine sind noch nicht veröffentlicht. Sie erhalten eine Nachricht, sobald es losgeht.
                    @endif
                    @if ($verwaltung)
                        <span class="text-amber-800">(Als Verwaltung kannst du trotzdem buchen.)</span>
                    @endif
                </div>
            @endunless
            @if ($offen && $s->nurWunschgespraeche())
                <div class="mt-3 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm text-indigo-900">
                    Sie sind persönlich eingeladen: Zurzeit können Sie nur die Gespräche buchen, bei denen „möchte Sie gern sprechen“ steht.
                    Alle übrigen Lehrkräfte folgen später – Sie bekommen dann noch eine E-Mail.
                </div>
            @endif

            <div x-data="{ reiter: @js($start) }">
            @if ($mitReitern)
                <nav class="mt-4 flex flex-wrap gap-1 border-b border-gray-200" role="tablist">
                    <button type="button" role="tab" :aria-selected="reiter === 'assistent'" @click="reiter = 'assistent'" class="{{ $reiterKlasse }}"
                            :class="reiter === 'assistent' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700'">Termin-Assistent</button>
                    <button type="button" role="tab" :aria-selected="reiter === 'manuell'" @click="reiter = 'manuell'" class="{{ $reiterKlasse }}"
                            :class="reiter === 'manuell' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700'">Manuelle Termine</button>
                </nav>

                <div x-show="reiter === 'assistent'" x-cloak>
                    @include('elternsprechtag::eltern.assistent', ['vorschlag' => $vorschlaege[$s->id] ?? null])
                </div>
            @endif

            <div x-show="reiter === 'manuell'" x-cloak>
            @foreach ($eintrag['kinder'] as $z)
                <h3 class="mt-5 text-base font-semibold text-gray-800">
                    {{ \Intranet\Modules\Elternsprechtag\Support\Namen::vorname($z['kind']->name) }} <span class="font-normal text-gray-500">· Klasse {{ implode(', ', $z['klassen']) }}</span>
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
                                $darfBuchen = ($offen && ! $l['gesperrt']) || $verwaltung;
                            @endphp
                            {{-- Klassenlehrer stehen vorn und sind kräftiger gerahmt – sie sind der erste Ansprechpartner. --}}
                            <div class="rounded-lg px-4 py-3 {{ $termin ? 'border-green-300 bg-green-50/60' : ($l['klassenlehrer'] ? 'bg-indigo-50/60' : '') }} {{ $l['klassenlehrer'] ? 'border-2 border-indigo-400 shadow-sm' : 'border '.($termin ? '' : 'border-gray-200') }}">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        @if ($l['klassenlehrer'] && ! $l['team'])
                                            <div class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Klassenlehrkraft</div>
                                        @endif
                                        @if ($l['team'])
                                            <div class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Klassenlehrkräfte · gemeinsames Gespräch</div>
                                        @endif
                                        <div class="{{ $l['klassenlehrer'] ? 'text-base font-semibold' : 'font-medium' }} text-gray-800">{{ $l['namen'] }}</div>
                                        @if ($plan->raum)
                                            <div class="text-xs text-gray-500">Raum: {{ $plan->raum }}</div>
                                        @endif
                                    </div>
                                    @if ($l['wunsch'] && ! $termin)
                                        <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs text-indigo-800">{{ $l['team'] ? 'möchten' : 'möchte' }} Sie gern sprechen</span>
                                    @endif
                                </div>
                                @if ($l['ausweichen'] && ! $termin)
                                    <p class="mt-2 text-xs text-amber-800">Ein gemeinsamer Termin ist nicht mehr frei. Sie können bei einer der Klassenlehrkräfte buchen – beide erfahren davon.</p>
                                @endif

                                @if ($termin)
                                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-sm">
                                        <span class="font-medium text-green-800">Ihr Termin: {{ $termin->tag?->beschriftung() }}, {{ $termin->von }}–{{ $termin->bis }} Uhr @if ($l['team'] && ! $termin->gruppe) <span class="font-normal">(nur bei {{ \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($l['team']->firstWhere('id', $termin->plan_id)?->user?->name) }})</span>@endif</span>
                                        @if ($darfBuchen)
                                            <form method="POST" action="{{ route('module.elternsprechtag.eltern.absagen', [$termin] + $als) }}"
                                                  data-bestaetigen="Den Termin bei {{ $l['namen'] }} wirklich absagen?"
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
                                                    'wert' => $sl['tag_id'].'|'.$sl['von'].(isset($sl['plan']) ? '|'.$sl['plan'] : ''),
                                                    'text' => $sl['von'].'–'.$sl['bis'].' Uhr'.(isset($sl['nur']) ? ' (nur '.$sl['nur'].')' : ''), 'gruppe' => $sl['tag'],
                                                ])->values()->all(),
                                                'leer' => 'Uhrzeit wählen …',
                                                'breite' => 'w-56',
                                            ])
                                            <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-indigo-700">
                                                {{ $termin ? 'Umbuchen' : 'Buchen' }}
                                            </button>
                                        </form>
                                    @endif
                                @elseif ($offen && $l['gesperrt'])
                                    <p class="mt-2 text-sm text-gray-500">Buchbar, sobald alle Gespräche freigegeben sind.</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            @endforeach
            </div>
            </div>

            {{-- Rückmeldung „keine Zeit": dann muss niemand hinterhertelefonieren. --}}
            @if ($s->veroeffentlicht() && ($offen || $verwaltung))
                @php $kz = $keineZeit[$s->id] ?? null; @endphp
                <div id="keine-zeit-{{ $s->id }}" class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700" x-data="{ auf: false }">
                    @if ($kz)
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>Sie haben am {{ \Illuminate\Support\Carbon::parse($kz->keine_zeit_am)->format('d.m.Y') }} gemeldet, dass Sie diesmal keinen Termin wahrnehmen können.
                                @if ($kz->keine_zeit_bemerkung) <span class="text-gray-500">„{{ $kz->keine_zeit_bemerkung }}“</span>@endif</span>
                            <form method="POST" action="{{ route('module.elternsprechtag.eltern.keinezeit', [$s] + $als) }}">
                                @csrf <input type="hidden" name="melden" value="0">
                                <button type="submit" class="text-xs text-indigo-700 hover:underline">Meldung zurücknehmen</button>
                            </form>
                        </div>
                    @else
                        <button type="button" @click="auf = ! auf" class="text-indigo-700 hover:underline">Sie können diesmal keinen Termin wahrnehmen? Geben Sie uns kurz Bescheid.</button>
                        <form x-show="auf" x-cloak method="POST" action="{{ route('module.elternsprechtag.eltern.keinezeit', [$s] + $als) }}" class="mt-2 space-y-2">
                            @csrf <input type="hidden" name="melden" value="1">
                            <textarea name="bemerkung" rows="2" maxlength="1000" class="w-full rounded-lg border-gray-300 text-sm"
                                      placeholder="Optional: Nachricht an die Schule, z. B. „Wir melden uns für einen eigenen Termin.“"></textarea>
                            <button type="submit" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Ich kann diesmal keinen Termin wahrnehmen</button>
                        </form>
                    @endif
                </div>
            @endif
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
