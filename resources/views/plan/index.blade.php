<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-800">Mein Terminplan</h1>
    </x-slot>

    @include('elternsprechtag::partials.meldung')

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-3">
        @forelse ($sprechtage as $s)
            @php
                $plan = $plaene->get($s->id);
            @endphp
            <a href="{{ route('module.elternsprechtag.plan.zeigen', $s) }}"
               class="block rounded-xl border border-gray-200 bg-white p-5 hover:border-indigo-300 hover:bg-indigo-50/40">
                <div class="flex items-start justify-between gap-2">
                    <span class="font-semibold text-gray-800">{{ $s->titel }}</span>
                    @if (! $plan)
                        <span class="rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">offen</span>
                    @elseif ($plan->abgeschlossen_am)
                        <span class="rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700">fertig</span>
                    @else
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700">in Arbeit</span>
                    @endif
                </div>
                <div class="mt-1 text-sm text-gray-600">{{ $s->tageText() }}</div>
                <div class="mt-1 text-sm text-gray-500">Meine Klassen: {{ implode(', ', array_intersect($s->klassenListe(), $eigene)) }}</div>
                @if ($s->lehrer_frist)
                    <div class="mt-2 text-xs {{ $s->fristVorbei() ? 'text-gray-400' : 'font-medium text-indigo-700' }}">
                        {{ $s->fristVorbei() ? 'Frist abgelaufen am' : 'Bitte eintragen bis' }} {{ $s->lehrer_frist->format('d.m.Y') }}
                    </div>
                @endif
            </a>
        @empty
            <p class="text-sm text-gray-400">
                @if ($eigene === [])
                    Du bist in keiner Klasse als Lehrer eingetragen – deshalb gibt es hier keinen Terminplan.
                    Lehrer sehen an dieser Stelle die Elternsprechtage ihrer Klassen.
                @else
                    Kein Elternsprechtag, an dem eine deiner Klassen beteiligt ist.
                @endif
            </p>
        @endforelse
    </div>
</x-app-layout>
