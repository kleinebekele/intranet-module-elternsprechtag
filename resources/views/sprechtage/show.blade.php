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
                @if ($sprechtag->buchungsschluss())
                    <div><dt class="text-gray-500">Frist für die Eltern</dt>
                        <dd>{{ $sprechtag->buchungsschluss()->format('d.m.Y, H:i') }} Uhr
                            @if (! $sprechtag->eltern_frist) <span class="text-xs text-gray-500">(Standard: Vorabend 18 Uhr)</span>@endif
                            @if ($sprechtag->schluss_gemeldet_am) <span class="text-xs text-gray-500">(abgelaufen – Terminlisten an die Lehrkräfte verschickt)</span>
                            @else <span class="text-xs text-gray-500">(danach buchen nur noch Verwaltung; alle Lehrkräfte bekommen ihre Terminliste per Mail, volle Pläne schon vorher)</span>@endif</dd></div>
                @endif
            </dl>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 xl:col-span-2">
            <h2 class="text-base font-semibold text-gray-800">Veröffentlichen</h2>
            @php
                // Pflicht: Klassenlehrer der beteiligten Klassen (freigegeben oder abgesagt). Alle anderen dürfen fehlen.
                $pflichtFehlt = $sprechtag->info_versendet_am ? collect() : $lehrer->filter(fn ($z) => $z['pflichtFehlt']);
                $ohnePlan = $lehrer->reject(fn ($z) => $plaene->has($z['user']->id))->count();
                $nichtFreigegeben = $lehrer->filter(fn ($z) => $plaene->has($z['user']->id) && ! $z['angabe'])->count();
                $mitAdresse = $eltern->reject(fn ($u) => $u->istGesperrt() || blank($u->email));
                $eingeladen = $mitAdresse->filter(fn ($u) => $einladungen->has($u->id))->count();
                $sperre = $eltern->isEmpty() || $sprechtag->tage->isEmpty() || $pflichtFehlt->isNotEmpty();
                $folgen = ' Danach können Lehrkräfte ihre Pläne nicht mehr ändern.'
                    .($ohnePlan > 0 ? ' '.$ohnePlan.' '.($ohnePlan === 1 ? 'Kollege hat' : 'Kollegen haben').' noch keine Angabe gemacht und '.($ohnePlan === 1 ? 'kann sich' : 'können sich').' dann nicht mehr für diesen Elternsprechtag anmelden.' : '')
                    .($nichtFreigegeben > 0 ? ' '.$nichtFreigegeben.' '.($nichtFreigegeben === 1 ? 'Plan ist' : 'Pläne sind').' nicht freigegeben und '.($nichtFreigegeben === 1 ? 'wird' : 'werden').' so veröffentlicht, wie '.($nichtFreigegeben === 1 ? 'er gespeichert ist' : 'sie gespeichert sind').'.' : '');
                $gebuchteWuensche = $wunschgespraeche->filter(fn ($w) => $w['termin'])->count();
                $knopf = 'rounded-lg px-4 py-2 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50';
            @endphp
            <p class="mt-1 text-sm text-gray-500">
                Gibt die Termine der Lehrkräfte für die Eltern zur Buchung frei – danach können Lehrkräfte ihre Pläne nicht mehr ändern.
                Das geht in zwei Stufen: <strong>1. Wunschgespräche</strong> – nur Eltern, die eine Lehrkraft unbedingt sprechen möchte, bekommen die Mail
                und können nur diese Gespräche buchen. <strong>2. Alle Gespräche</strong> – die Mail geht an alle Eltern der beteiligten Klassen
                ({{ $eltern->count() }} Personen, {{ $mitAdresse->count() }} mit Adresse und nicht gesperrt), alle Termine sind buchbar.
                Den Wortlaut beider Mails gibt es unter Verwaltung → Mailvorlagen („Elternsprechtag: Einladung zu Wunschgesprächen" und „Elternsprechtag: Info an alle Eltern"); der Hinweistext vom Sprechtag wird eingesetzt.
            </p>
            @if ($sprechtag->hinweis)
                <div class="mt-3 whitespace-pre-line rounded-lg bg-gray-50 p-3 text-sm text-gray-700">{{ $sprechtag->hinweis }}</div>
            @endif
            <div class="mt-3 flex flex-wrap items-center gap-3">
                @darfRoute('module.elternsprechtag.sprechtage.info')
                    @if (! $sprechtag->veroeffentlicht())
                        @if ($wunschgespraeche->isNotEmpty())
                            <form method="POST" action="{{ route('module.elternsprechtag.sprechtage.info', $sprechtag) }}"
                                  data-bestaetigen="{{ 'Die Wunschgespräche werden veröffentlicht: '.$eingeladen.' persönlich eingeladene Eltern bekommen die Mail und können nur diese Gespräche buchen. Alle anderen Eltern folgen, wenn du im zweiten Schritt alle Gespräche veröffentlichst.'.$folgen }}"
                                  data-titel="1. Wunschgespräche veröffentlichen" data-knopf="Veröffentlichen">
                                @csrf
                                <input type="hidden" name="stufe" value="wunsch">
                                <button type="submit" @disabled($sperre) class="{{ $knopf }} bg-indigo-600 text-white hover:bg-indigo-700">1. Wunschgespräche veröffentlichen</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('module.elternsprechtag.sprechtage.info', $sprechtag) }}"
                              data-bestaetigen="{{ 'Alle Gespräche werden auf einmal veröffentlicht: '.$mitAdresse->count().' Eltern bekommen die Mail.'.($wunschgespraeche->isNotEmpty() ? ' Die persönlich eingeladenen Eltern haben dann keinen Vorsprung.' : '').$folgen }}"
                              data-titel="Alle Gespräche veröffentlichen" data-knopf="Veröffentlichen">
                            @csrf
                            <input type="hidden" name="stufe" value="alle">
                            <button type="submit" @disabled($sperre)
                                    class="{{ $knopf }} {{ $wunschgespraeche->isNotEmpty() ? 'border border-gray-300 text-gray-700 hover:bg-gray-50' : 'bg-indigo-600 text-white hover:bg-indigo-700' }}">
                                {{ $wunschgespraeche->isNotEmpty() ? 'Gleich alle Gespräche veröffentlichen' : 'Veröffentlichen' }}
                            </button>
                        </form>
                    @elseif ($sprechtag->nurWunschgespraeche())
                        <form method="POST" action="{{ route('module.elternsprechtag.sprechtage.info', $sprechtag) }}"
                              data-bestaetigen="{{ 'Jetzt alle Gespräche veröffentlichen? '.$mitAdresse->count().' Eltern bekommen die Mail – auch die persönlich eingeladenen, damit sie wissen, dass jetzt alles buchbar ist. Bisher haben '.$gebuchteWuensche.' von '.$wunschgespraeche->count().' Wunschgesprächen einen Termin.' }}"
                              data-titel="2. Alle Gespräche veröffentlichen" data-knopf="Veröffentlichen">
                            @csrf
                            <input type="hidden" name="stufe" value="alle">
                            <button type="submit" class="{{ $knopf }} bg-indigo-600 text-white hover:bg-indigo-700">2. Alle Gespräche veröffentlichen</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('module.elternsprechtag.sprechtage.info', $sprechtag) }}"
                              data-bestaetigen="{{ 'Die Info ging schon am '.$sprechtag->info_rest_versendet_am->format('d.m.Y').' an alle raus. Wirklich NOCHMAL an '.$mitAdresse->count().' Eltern verschicken?' }}"
                              data-titel="Info erneut senden" data-knopf="Senden">
                            @csrf
                            <input type="hidden" name="stufe" value="alle">
                            <button type="submit" class="{{ $knopf }} border border-gray-300 text-gray-700 hover:bg-gray-50">Info erneut senden</button>
                        </form>
                    @endif
                @enddarfRoute
                @if ($sprechtag->nurWunschgespraeche())
                    <span class="text-sm text-amber-700">✓ Wunschgespräche veröffentlicht am {{ $sprechtag->info_versendet_am->format('d.m.Y H:i') }}, Info an {{ $sprechtag->info_empfaenger }} Eltern · alle übrigen Gespräche sind noch nicht buchbar</span>
                @elseif ($sprechtag->fuerAlleOffen())
                    <span class="text-sm text-green-700">✓ alle Gespräche veröffentlicht am {{ $sprechtag->info_rest_versendet_am->format('d.m.Y H:i') }}, Info an {{ $sprechtag->info_empfaenger }} Eltern
                        @if ($sprechtag->info_rest_versendet_am->ne($sprechtag->info_versendet_am)) <span class="text-gray-500">(Wunschgespräche seit {{ $sprechtag->info_versendet_am->format('d.m.Y H:i') }})</span>@endif</span>
                @endif
                @if ($zustellung && $zustellung['wartend'] > 0)
                    <span class="text-sm text-gray-500">· {{ $zustellung['wartend'] }} noch im Ausgangskorb</span>
                @endif
                @if ($zustellung && $zustellung['fehlgeschlagen']->isNotEmpty())
                    <details class="w-full text-sm">
                        <summary class="cursor-pointer font-medium text-red-700">⚠ {{ $zustellung['fehlgeschlagen']->count() }} {{ $zustellung['fehlgeschlagen']->count() === 1 ? 'Mail wurde' : 'Mails wurden' }} nicht zugestellt – bitte diese Eltern anders erreichen</summary>
                        <ul class="mt-1 space-y-0.5 pl-5 text-gray-700">
                            @foreach ($zustellung['fehlgeschlagen'] as $f)
                                <li>
                                    @if ($f['eltern'])
                                        <a href="{{ route('module.elternsprechtag.eltern.index', ['eltern' => $f['eltern']->id]) }}" class="text-indigo-700 hover:underline">{{ $f['eltern']->name }}</a>
                                    @endif
                                    <span class="text-gray-500">{{ $f['an'] }}</span>
                                    @if ($f['grund'] !== '') <span class="text-xs text-gray-400">· {{ \Illuminate\Support\Str::limit($f['grund'], 120) }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                        @if (auth()->user()?->is_admin && \Illuminate\Support\Facades\Route::has('admin.mail.index'))
                            <a href="{{ route('admin.mail.index', ['status' => 'fehlgeschlagen']) }}" class="mt-1 inline-block pl-5 text-xs text-indigo-700 hover:underline">im Ausgangskorb ansehen</a>
                        @endif
                    </details>
                @endif
            </div>

            {{-- Stand der Wunschgespräche: wer hat schon gebucht, wen muss man evtl. anrufen. --}}
            @if ($wunschgespraeche->isNotEmpty())
                @php $offeneWuensche = $wunschgespraeche->count() - $gebuchteWuensche; @endphp
                <div id="wunschgespraeche" class="mt-4 rounded-lg border border-indigo-200 bg-indigo-50/50 px-3 py-2" x-data="{ offen: @js((bool) session('elternsprechtag_wunsch_offen')) }">
                    <div role="button" tabindex="0" @click="offen = ! offen" @keydown.enter.prevent="offen = ! offen" class="flex cursor-pointer items-center justify-between gap-2">
                        <div class="text-sm">
                            @if ($sprechtag->veroeffentlicht())
                                <span class="font-semibold text-gray-800">Wunschgespräche: {{ $gebuchteWuensche }} von {{ $wunschgespraeche->count() }} gebucht</span>
                            @else
                                <span class="font-semibold text-gray-800">{{ $wunschgespraeche->count() }} Wunschgespräche</span>
                                <span class="text-gray-500">· nach dem Veröffentlichen siehst du hier, wer schon gebucht hat</span>
                            @endif
                            @if ($offeneWuensche > 0 && $sprechtag->veroeffentlicht())
                                <span class="text-amber-800">· {{ $offeneWuensche }} noch ohne Termin</span>
                            @endif
                        </div>
                        <i class="bx bx-chevron-down text-2xl text-gray-500 transition-transform" :class="offen && 'rotate-180'"></i>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-indigo-100" @unless ($sprechtag->veroeffentlicht()) hidden @endunless>
                        <div class="h-2 rounded-full bg-indigo-600" style="width: {{ round($gebuchteWuensche / $wunschgespraeche->count() * 100) }}%"></div>
                    </div>
                    <div x-show="offen" x-cloak class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr><th class="py-1 pr-3">Kind</th><th class="py-1 pr-3">Klasse</th><th class="py-1 pr-3">Gespräch mit</th><th class="py-1 pr-3">Eltern</th><th class="py-1">Termin</th></tr>
                            </thead>
                            @php $darfBuchen = \Intranet\Modules\Elternsprechtag\Support\Rechte::istVerwaltung(auth()->user()) && $sprechtag->veroeffentlicht(); @endphp
                                @foreach ($wunschgespraeche as $w)
                                    @php
                                        $buchbar = $darfBuchen && ! $w['termin'] && $w['eltern']->isNotEmpty();
                                        $zeitenUrl = route('module.elternsprechtag.eltern.zeiten', [$sprechtag, 'kind' => $w['kind']->id, 'plan' => $w['plan_id']]);
                                    @endphp
                                    <tbody class="border-t border-indigo-100" @if ($buchbar) x-data="wunschBuchen(@js($zeitenUrl), @js((string) $w['eltern']->first()->id))" @endif>
                                    <tr>
                                        <td class="py-1 pr-3 text-gray-800">{{ \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($w['kind']->name) }}</td>
                                        <td class="py-1 pr-3 text-gray-600">{{ $w['klasse'] }}</td>
                                        <td class="py-1 pr-3 text-gray-600">{{ $w['lehrer'] }}</td>
                                        <td class="py-1 pr-3 text-gray-600">
                                            @forelse ($w['eltern'] as $e)
                                                <span class="whitespace-nowrap">
                                                @darfRoute('module.elternsprechtag.eltern.index')
                                                    <a href="{{ route('module.elternsprechtag.eltern.index', ['eltern' => $e->id]) }}" class="text-indigo-700 hover:underline" title="Für dieses Elternteil buchen (z. B. nach einem Anruf)">{{ \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($e->name) }}</a>
                                                @else
                                                    {{ \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($e->name) }}
                                                @enddarfRoute
                                                @include('elternsprechtag::partials.eltern-status')@if (! $loop->last),@endif
                                                </span>
                                            @empty
                                                <span class="text-gray-400">keine hinterlegt</span>
                                            @endforelse
                                        </td>
                                        <td class="py-1">
                                            @php $kz = $w['termin'] ? null : $w['eltern']->map(fn ($e) => $keineZeit[$e->id] ?? null)->filter()->first(); @endphp
                                            @if ($w['termin'])
                                                <span class="text-green-700">✓ {{ $w['termin']->tag?->beschriftung() }}, {{ $w['termin']->von }} Uhr</span>
                                            @elseif ($buchbar)
                                                <button type="button" @click="umschalten()" class="inline-flex items-center gap-1 text-left hover:underline {{ $kz ? 'text-gray-500' : 'text-amber-700' }}" title="Freie Uhrzeiten zeigen und Termin vereinbaren">
                                                    {{ $kz ? 'keine Zeit gemeldet' : 'noch kein Termin' }}
                                                    <i class="bx bx-calendar-plus text-base"></i>
                                                </button>
                                                @if ($kz && $kz->keine_zeit_bemerkung)
                                                    <i class="bx bx-info-circle text-base align-middle text-gray-500 cursor-help" title="{{ $kz->keine_zeit_bemerkung }}"></i>
                                                @endif
                                            @elseif ($kz)
                                                <span class="text-gray-500">keine Zeit gemeldet @if ($kz->keine_zeit_bemerkung)<i class="bx bx-info-circle text-base align-middle cursor-help" title="{{ $kz->keine_zeit_bemerkung }}"></i>@endif</span>
                                            @else
                                                <span class="text-amber-700">noch kein Termin</span>
                                            @endif
                                        </td>
                                    </tr>
                                    @if ($buchbar)
                                        <tr x-show="offen" x-cloak>
                                            <td colspan="5" class="pb-3 pt-1">
                                                <div class="rounded-lg border border-indigo-200 bg-white px-3 py-2">
                                                    <template x-if="laedt"><p class="text-sm text-gray-500">Freie Uhrzeiten werden geladen …</p></template>
                                                    <template x-if="! laedt && daten">
                                                        <form method="POST" action="{{ route('module.elternsprechtag.eltern.buchen', $sprechtag) }}" class="flex flex-wrap items-end gap-3 text-sm">
                                                            @csrf
                                                            <input type="hidden" name="zurueck" value="sprechtag">
                                                            <input type="hidden" name="kind_id" value="{{ $w['kind']->id }}">
                                                            <input type="hidden" name="plan_id" :value="daten.plan_id">
                                                            <div class="w-full">
                                                                <span class="font-medium text-gray-800" x-text="daten.namen"></span>
                                                                <span x-show="daten.team" class="text-indigo-700">· gemeinsames Gespräch</span>
                                                                <span x-show="daten.ausweichen" class="text-amber-800">· gemeinsam ist keine Uhrzeit mehr frei – gebucht wird nur bei der genannten Lehrkraft</span>
                                                            </div>
                                                            @if ($w['eltern']->count() > 1)
                                                                <label class="flex flex-col gap-1">
                                                                    <span class="text-xs text-gray-500">Für</span>
                                                                    <select name="eltern" x-model="eltern" @change="laden()" class="rounded-lg border-gray-300 py-1.5 text-sm">
                                                                        @foreach ($w['eltern'] as $e)
                                                                            <option value="{{ $e->id }}">{{ \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($e->name) }}</option>
                                                                        @endforeach
                                                                    </select>
                                                                </label>
                                                            @else
                                                                <input type="hidden" name="eltern" value="{{ $w['eltern']->first()->id }}">
                                                            @endif
                                                            <template x-if="daten.slots.length">
                                                                <div class="w-full space-y-2">
                                                                    <template x-for="tag in tage()" :key="tag">
                                                                        <div>
                                                                            <div class="text-xs text-gray-500">Frei am <span x-text="tag"></span>:</div>
                                                                            <div class="mt-1 flex flex-wrap gap-1.5">
                                                                                <template x-for="s in daten.slots.filter(x => x.tag === tag)" :key="s.wert">
                                                                                    <label class="cursor-pointer">
                                                                                        <input type="radio" name="slot" required :value="s.wert" x-model="slot" class="sr-only">
                                                                                        <span class="inline-block rounded-md border px-2 py-1 text-xs"
                                                                                              :class="slot === s.wert ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-indigo-200 bg-indigo-50 text-indigo-800 hover:border-indigo-400'" x-text="s.text.replace(' Uhr', '')"></span>
                                                                                    </label>
                                                                                </template>
                                                                            </div>
                                                                        </div>
                                                                    </template>
                                                                </div>
                                                            </template>
                                                            <template x-if="daten.slots.length">
                                                                <button type="submit" :disabled="! slot" :class="slot ? 'hover:bg-indigo-700' : 'cursor-not-allowed opacity-50'" class="rounded-lg bg-indigo-600 px-3 py-1.5 font-medium text-white">Termin buchen</button>
                                                            </template>
                                                            <template x-if="! daten.slots.length">
                                                                <p class="text-red-700">Keine freie Uhrzeit mehr – bitte mit den Lehrkräften klären.</p>
                                                            </template>
                                                        </form>
                                                    </template>
                                                    <template x-if="fehler"><p class="text-sm text-red-700" x-text="fehler"></p></template>
                                                </div>
                                            </td>
                                        </tr>
                                    @endif
                                    </tbody>
                                @endforeach
                        </table>
                        <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                            <span><i class="bx bx-user-check align-middle text-green-600"></i>/<i class="bx bx-user-x align-middle text-gray-400"></i> Konto eingerichtet / noch nicht</span>
                            <span><i class="bx bx-show align-middle text-green-600"></i>/<i class="bx bx-hide align-middle text-gray-400"></i> Terminseite besucht / noch nicht</span>
                            <span><i class="bx bx-envelope align-middle text-red-600"></i> keine Mailadresse</span>
                        </p>
                    </div>
                </div>
            @endif

            @if ($wunschgespraeche->isNotEmpty())
                <script>
                    // Wunschgespräch direkt aus der Liste buchen: freie Uhrzeiten erst beim Aufklappen laden.
                    function wunschBuchen(url, eltern) {
                        return {
                            offen: false, laedt: false, daten: null, fehler: '', eltern: eltern, slot: '',
                            umschalten() {
                                this.offen = ! this.offen;
                                if (this.offen && ! this.daten) this.laden();
                            },
                            async laden() {
                                this.laedt = true; this.fehler = ''; this.slot = '';
                                try {
                                    const antwort = await fetch(url + '&eltern=' + encodeURIComponent(this.eltern), { headers: { Accept: 'application/json' } });
                                    if (! antwort.ok) throw new Error();
                                    this.daten = await antwort.json();
                                } catch (e) {
                                    this.daten = null; this.fehler = 'Die freien Uhrzeiten konnten nicht geladen werden.';
                                }
                                this.laedt = false;
                            },
                            tage() { return [...new Set(this.daten.slots.map(s => s.tag))]; },
                        };
                    }
                </script>
            @endif

            {{-- Stand der normalen Einladung: je Kind, ob schon ein Gespräch gebucht ist (egal von welchem Elternteil). --}}
            @if ($elternStand)
                @php
                    $mitTermin = $elternStand->filter(fn ($z) => $z['termine'] > 0)->count();
                    $kzVon = fn ($z) => $z['eltern']->map(fn ($e) => $keineZeit[$e->id] ?? null)->filter()->first();
                    $ohneZeit = $elternStand->filter(fn ($z) => $z['termine'] === 0 && $kzVon($z))->count();
                @endphp
                <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2" x-data="{ offen: false }">
                    <div role="button" tabindex="0" @click="offen = ! offen" @keydown.enter.prevent="offen = ! offen" class="flex cursor-pointer items-center justify-between gap-2">
                        <div class="text-sm">
                            <span class="font-semibold text-gray-800">Alle Kinder: {{ $mitTermin }} von {{ $elternStand->count() }} haben einen Termin</span>
                            @if ($elternStand->count() - $mitTermin - $ohneZeit > 0)
                                <span class="text-amber-800">· {{ $elternStand->count() - $mitTermin - $ohneZeit }} noch ohne Termin</span>
                            @endif
                            @if ($ohneZeit > 0)
                                <span class="text-gray-500">· bei {{ $ohneZeit }} haben die Eltern „keine Zeit“ gemeldet</span>
                            @endif
                        </div>
                        <i class="bx bx-chevron-down text-2xl text-gray-500 transition-transform" :class="offen && 'rotate-180'"></i>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-gray-200">
                        <div class="h-2 rounded-full bg-indigo-600" style="width: {{ $elternStand->isEmpty() ? 0 : round($mitTermin / $elternStand->count() * 100) }}%"></div>
                    </div>
                    <div x-show="offen" x-cloak class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                                <tr><th class="py-1 pr-3">Kind</th><th class="py-1 pr-3">Klasse</th><th class="py-1 pr-3">Eltern</th><th class="py-1">Gespräche</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @foreach ($elternStand as $z)
                                    <tr>
                                        <td class="py-1 pr-3 text-gray-800">{{ \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($z['kind']->name) }}</td>
                                        <td class="py-1 pr-3 text-gray-600">{{ $z['klassen'] }}</td>
                                        <td class="py-1 pr-3 text-gray-600">
                                            @foreach ($z['eltern'] as $e)
                                                <span class="whitespace-nowrap">
                                                @darfRoute('module.elternsprechtag.eltern.index')
                                                    <a href="{{ route('module.elternsprechtag.eltern.index', ['eltern' => $e->id]) }}" class="text-indigo-700 hover:underline" title="Für dieses Elternteil buchen (z. B. nach einem Anruf)">{{ \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($e->name) }}</a>
                                                @else
                                                    {{ \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($e->name) }}
                                                @enddarfRoute
                                                @include('elternsprechtag::partials.eltern-status')@if (! $loop->last),@endif
                                                </span>
                                            @endforeach
                                        </td>
                                        <td class="py-1">
                                            @if ($z['termine'] > 0)
                                                <span class="text-green-700">✓ {{ $z['termine'] }} gebucht</span>
                                            @else
                                                @if ($kz = $kzVon($z))
                                                    <span class="text-gray-500">keine Zeit gemeldet @if ($kz->keine_zeit_bemerkung)<i class="bx bx-info-circle text-base align-middle cursor-help" title="{{ $kz->keine_zeit_bemerkung }}"></i>@endif</span>
                                                @else
                                                    <span class="text-amber-700">noch keins</span>
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                            <span><i class="bx bx-user-check align-middle text-green-600"></i>/<i class="bx bx-user-x align-middle text-gray-400"></i> Konto eingerichtet / noch nicht</span>
                            <span><i class="bx bx-show align-middle text-green-600"></i>/<i class="bx bx-hide align-middle text-gray-400"></i> Terminseite besucht / noch nicht</span>
                            <span><i class="bx bx-envelope align-middle text-red-600"></i> keine Mailadresse</span>
                        </p>
                    </div>
                </div>
            @endif

            @if ($pflichtFehlt->isNotEmpty())
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    Veröffentlichen geht erst, wenn alle Klassenlehrkräfte ihren Plan freigegeben oder ihre Teilnahme abgesagt haben. Es fehlen noch:
                    <span class="font-medium">{{ $pflichtFehlt->map(fn ($z) => \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($z['user']->name).' ('.implode(', ', $z['klassenlehrer']).')')->join(' · ') }}</span>
                </div>
            @endif

            {{-- Vorschau der Info-Mail für ein bestimmtes Elternteil; eingeladene Eltern stehen oben. --}}
            @if ($eltern->isNotEmpty() && $sprechtag->tage->isNotEmpty())
                @php
                    $nv = fn ($n) => \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($n);
                    $optionen = $eltern->sortBy(fn ($u) => $einladungen->has($u->id) ? 0 : 1, SORT_REGULAR, false)
                        ->map(fn ($u) => [
                            'wert' => (string) $u->id,
                            'text' => $nv($u->name).($einladungen->has($u->id) ? ' ('.count($einladungen->get($u->id)).'× eingeladen)' : ''),
                            'gruppe' => $einladungen->has($u->id) ? 'Persönlich eingeladen' : 'Alle anderen Eltern',
                        ])->values();
                    $erste = $optionen->first()['wert'];
                @endphp
                <div class="mt-5 border-t border-gray-100 pt-4"
                     x-data="mailVorschau(@js(route('module.elternsprechtag.sprechtage.vorschau', $sprechtag)), @js($erste), @js($einladungen->isNotEmpty() && ! $sprechtag->fuerAlleOffen() ? 'wunsch' : 'alle'))"
                     @suchauswahl-gewaehlt="laden($event.detail.wert)">
                    <div role="button" tabindex="0" :aria-expanded="offen" @click="umschalten()" @keydown.enter.prevent="umschalten()" @keydown.space.prevent="umschalten()"
                         class="flex cursor-pointer items-center justify-between gap-2">
                        <h3 class="text-sm font-semibold text-gray-800">Vorschau der Mails an die Eltern
                            <span class="font-normal text-gray-500">· {{ $einladungen->count() }} Eltern persönlich eingeladen</span></h3>
                        <i class="bx bx-chevron-down text-2xl text-gray-500 transition-transform" :class="offen && 'rotate-180'"></i>
                    </div>
                    <div x-show="offen" x-cloak class="mt-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="text-sm text-gray-600">Mail für</span>
                        @include('elternsprechtag::partials.suchauswahl', [
                            'name' => 'vorschau_eltern',
                            'optionen' => $optionen->all(),
                            'wert' => $erste,
                            'leer' => 'Elternteil suchen …',
                            'breite' => 'w-80',
                        ])
                        @if ($einladungen->isNotEmpty())
                            <div class="flex rounded-lg border border-gray-300 text-sm" role="group">
                                <button type="button" @click="art = 'wunsch'; laden(aktuell)" class="rounded-l-lg px-3 py-1.5"
                                        :class="art === 'wunsch' ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-50'">1. Einladung Wunschgespräche</button>
                                <button type="button" @click="art = 'alle'; laden(aktuell)" class="rounded-r-lg border-l border-gray-300 px-3 py-1.5"
                                        :class="art === 'alle' ? 'bg-indigo-600 text-white' : 'text-gray-700 hover:bg-gray-50'">2. Info an alle</button>
                            </div>
                        @endif
                    </div>
                    <div class="mt-3 rounded-lg border border-gray-200">
                        <div class="space-y-0.5 border-b border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                            <div><span class="text-gray-500">An:</span> <span x-text="an"></span>
                                <span x-show="gesperrt" class="text-red-700">(gesperrt – bekommt keine Mail)</span></div>
                            <div><span class="text-gray-500">Betreff:</span> <span class="font-medium" x-text="betreff"></span></div>
                            <template x-if="konto !== null">
                                <div><span class="text-gray-500">Konto-Hinweis:</span>
                                    <span x-show="konto" class="text-amber-800">erscheint – dieses Elternteil hat noch kein Passwort</span>
                                    <span x-show="! konto" class="text-gray-600">entfällt – Konto ist eingerichtet</span>
                                    <span class="text-xs text-gray-400">(Platzhalter @{{ konto_einrichten }} in der Vorlage)</span></div>
                            </template>
                            <template x-if="anhaenge.length">
                                <div><span class="text-gray-500">Anhang:</span>
                                    <template x-for="a in anhaenge" :key="a.name">
                                        <a :href="a.url" target="_blank" class="mr-2 inline-flex items-center gap-1 text-indigo-700 hover:underline">📎 <span x-text="a.name"></span> <span class="text-gray-500" x-text="'(' + a.groesse + ')'"></span></a>
                                    </template>
                                </div>
                            </template>
                        </div>
                        <iframe x-ref="rahmen" class="h-[32rem] w-full rounded-b-lg" title="Vorschau der Mail"></iframe>
                    </div>
                    </div>
                </div>
                <script>
                    function mailVorschau(url, erste, art) {
                        return {
                            an: '', betreff: '', gesperrt: false, anhaenge: [], konto: null, offen: false, geladen: false, art: art, aktuell: erste,
                            // Erst beim ersten Aufklappen laden.
                            umschalten() {
                                this.offen = ! this.offen;
                                if (this.offen && ! this.geladen) { this.geladen = true; this.laden(erste); }
                            },
                            async laden(id) {
                                if (! id) return;
                                this.aktuell = id;
                                const antwort = await fetch(url + '?eltern=' + encodeURIComponent(id) + '&art=' + this.art, { headers: { Accept: 'application/json' } });
                                if (! antwort.ok) return;
                                const d = await antwort.json();
                                this.an = d.an; this.betreff = d.betreff; this.gesperrt = d.gesperrt; this.anhaenge = d.anhaenge || []; this.konto = d.konto ?? null;
                                this.$refs.rahmen.srcdoc = d.html;
                            },
                        };
                    }
                </script>
            @endif
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 xl:col-span-3">
            <h2 class="text-base font-semibold text-gray-800">Lehrer ({{ $lehrer->count() }})</h2>
            <p class="mt-1 text-sm text-gray-500">Lehrer laut Linear und alle, die selbst einen Plan angelegt haben. Klick auf den Namen öffnet den Terminplan.</p>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="py-2 pr-4">Lehrer</th>
                            <th class="py-2 pr-4">Klassenlehrer</th>
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
                                <td class="py-2 pr-4 {{ $z['klassenlehrer'] ? 'font-medium text-gray-800' : 'text-gray-400' }}">{{ implode(', ', $z['klassenlehrer']) ?: '–' }}</td>
                                <td class="py-2 pr-4">{{ implode(', ', $z['klassen']) ?: '–' }}</td>
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
                                    @if ($z['pflichtFehlt'] && ! $sprechtag->info_versendet_am)
                                        <div class="text-xs font-medium text-red-700">Pflicht als Klassenlehrkraft</div>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">{{ $plan?->tage->count() ?? '–' }}</td>
                                <td class="py-2 pr-4">{{ $plan ? $plan->termine->count().' / '.$plan->anzahlTermine() : '–' }}</td>
                                <td class="py-2 pr-4">{{ $plan?->wuensche->count() ?? '–' }}</td>
                                <td class="py-2 pr-4">{{ $plan?->raum ?: '–' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-3 text-gray-400">Keine Lehrer – Klassen wählen oder Linear-Abgleich prüfen.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
