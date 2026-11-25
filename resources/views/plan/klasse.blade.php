{{-- Für Klassenlehrkräfte: wer aus der eigenen Klasse wann bei wem einen Termin hat.
     Erwartet: $klasse = ['klassen' => list<string>, 'zeilen' => Collection], $nv. --}}
@php
    $mitTermin = $klasse['zeilen']->filter(fn ($z) => $z['termine']->isNotEmpty())->count();
@endphp
<div class="mb-4 rounded-xl border border-indigo-200 bg-white p-5" x-data="{ offen: false, suche: '' }">
    <div role="button" tabindex="0" @click="offen = ! offen" @keydown.enter.prevent="offen = ! offen" class="flex cursor-pointer items-center justify-between gap-2">
        <div>
            <h2 class="text-base font-semibold text-gray-800">Meine Klasse{{ count($klasse['klassen']) > 1 ? 'n' : '' }} {{ implode(', ', $klasse['klassen']) }}: Termine der Schüler</h2>
            <p class="text-sm text-gray-500">{{ $mitTermin }} von {{ $klasse['zeilen']->count() }} Kindern haben einen Termin – so siehst du, bei wem du ggf. dazukommen kannst.</p>
        </div>
        <i class="bx bx-chevron-down text-2xl text-gray-500 transition-transform" :class="offen && 'rotate-180'"></i>
    </div>
    <div x-show="offen" x-cloak class="mt-3">
        <input type="search" x-model="suche" placeholder="Kind suchen …" class="mb-2 w-full rounded-lg border-gray-300 text-sm sm:w-72">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                        <th class="py-2 pr-4">Kind</th>
                        <th class="py-2 pr-4">Klasse</th>
                        <th class="py-2">Termine</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($klasse['zeilen'] as $z)
                        @php $name = $nv($z['kind']->name); @endphp
                        <tr x-show="suche === '' || @js(mb_strtolower($name)).includes(suche.toLowerCase())">
                            <td class="py-2 pr-4 align-top font-medium text-gray-800">{{ $name }}</td>
                            <td class="py-2 pr-4 align-top text-gray-600">{{ $z['klasse'] }}</td>
                            <td class="py-2 align-top">
                                @forelse ($z['termine'] as $t)
                                    <div class="{{ $t['eigen'] ? 'text-green-800' : 'text-gray-800' }}">
                                        <span class="tabular-nums">{{ $t['zeit'] }}</span> · {{ $t['namen'] }}@if ($t['raum']) <span class="text-gray-500">· Raum {{ $t['raum'] }}</span>@endif
                                        @if ($t['eigen']) <span class="text-xs text-green-700">(bei dir)</span>@endif
                                    </div>
                                @empty
                                    <span class="text-gray-400">kein Termin</span>
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
