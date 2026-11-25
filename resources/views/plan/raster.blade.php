{{-- Einheitliches Raster eines Tages: je Termin frei, Pause, gesperrt oder Reserve.
     Erwartet: $t (Tag), $plan, $sprechtag, $darfMarkieren, $ziel, $nv.
     Bedienung über div statt button/select: ein deaktiviertes fieldset (Plan gesperrt) sperrt sonst
     auch das Markieren, das bis zum Sprechtag möglich bleibt. --}}
@php
    $pt = $plan->exists ? $plan->tage->firstWhere('tag_id', $t->id) : null;
    $raster = $pt ? \Intranet\Modules\Elternsprechtag\Support\Slots::raster($plan, $pt) : [];
    $termine = $plan->termine->where('tag_id', $t->id)->keyBy('von');
    $stati = \Intranet\Modules\Elternsprechtag\Support\Slots::STATUS;
    $wahl = \Intranet\Modules\Elternsprechtag\Support\Slots::MARKIERBAR;
@endphp
<div x-show="tage[id].dabei" class="mt-3">
    @if (! $pt)
        <p class="text-sm text-amber-700">Erst speichern – dann kannst du hier einzelne Termine als Pause, gesperrt oder Reserve markieren.</p>
    @else
        <p class="text-xs text-gray-500">
            Klick auf eine Uhrzeit: <strong>frei</strong> (buchbar), <strong>Pause</strong>, <strong>gesperrt</strong> (z. B. später da)
            oder <strong>Reserve</strong> (wird erst buchbar, wenn alle freien Termine vergeben sind). Gebuchte Termine lassen sich hier nicht ändern.
        </p>
        <div class="mt-2 flex flex-wrap gap-1.5">
            @foreach ($raster as $s)
                @php $termin = $termine->get($s['von']); @endphp
                @if (! $termin && $s['status'] === 'dazu')
                    <span class="rounded-lg border border-indigo-300 bg-indigo-50 px-2 py-1 text-xs tabular-nums text-indigo-900"
                          title="{{ $s['hinweis'] ?? '' }}">{{ $s['von'] }} <span class="font-medium">dazugebucht</span></span>
                @elseif ($termin)
                    <span class="rounded-lg border border-green-300 bg-green-50 px-2 py-1 text-xs tabular-nums text-green-900"
                          title="gebucht: {{ $nv($termin->kind?->name) }}">{{ $s['von'] }} <span class="font-medium">gebucht</span></span>
                @else
                    <div class="relative" x-data="{ s: @js($s['status']), auf: false, fehler: '' }" @click.outside="auf = false">
                        <div role="button" tabindex="0"
                             @if ($darfMarkieren) @click="auf = ! auf" @keydown.enter.prevent="auf = ! auf" @endif
                             class="select-none rounded-lg border px-2 py-1 text-xs tabular-nums {{ $darfMarkieren ? 'cursor-pointer' : 'cursor-default' }}"
                             :class="{ 'border-gray-300 bg-white text-gray-800': s === 'frei', 'border-gray-300 bg-gray-200 text-gray-600': s === 'pause',
                                       'border-red-300 bg-red-50 text-red-800': s === 'gesperrt', 'border-amber-300 bg-amber-50 text-amber-900': s === 'backup' }">
                            {{ $s['von'] }} <span class="font-medium" x-text="@js($stati)[s]"></span>
                        </div>
                        @if ($darfMarkieren)
                            <div x-show="auf" x-cloak class="absolute left-0 top-full z-30 mt-1 w-32 rounded-lg border border-gray-200 bg-white py-1 text-sm shadow-lg">
                                @foreach ($wahl as $wert => $text)
                                    <div role="button" tabindex="0" class="cursor-pointer px-3 py-1 hover:bg-gray-100"
                                         :class="s === @js($wert) && 'font-semibold'"
                                         @click="auf = false; const alt = s; s = @js($wert);
                                                 fetch(@js(route('module.elternsprechtag.plan.markieren', $ziel)), { method: 'POST',
                                                     headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) },
                                                     body: JSON.stringify({ tag_id: {{ $t->id }}, von: @js($s['von']), status: @js($wert) }) })
                                                 .then(r => r.json().then(d => { if (! r.ok) { s = alt; fehler = d.message || 'Nicht gespeichert.'; alert(fehler); } }))
                                                 .catch(() => { s = alt; alert('Nicht gespeichert.'); })">{{ $text }}</div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            @endforeach
            @if ($raster === [])
                <span class="text-xs text-amber-700">Keine Termine – bitte beim Sekretariat Beginn und Ende des Tages prüfen lassen.</span>
            @endif
        </div>
    @endif
</div>
