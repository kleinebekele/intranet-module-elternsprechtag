<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold text-gray-800">Elternsprechtage</h1>
            @darfRoute('module.elternsprechtag.sprechtage.create')
                <a href="{{ route('module.elternsprechtag.sprechtage.create') }}"
                   class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Neuer Elternsprechtag</a>
            @enddarfRoute
        </div>
    </x-slot>

    @include('elternsprechtag::partials.meldung')

    @foreach (['Anstehend' => $kommend, 'Vergangen' => $vergangen] as $ueberschrift => $liste)
        @if ($ueberschrift === 'Anstehend' || $liste->isNotEmpty())
            <div class="mb-4 rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="text-base font-semibold text-gray-800">{{ $ueberschrift }}</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="py-2 pr-4">Titel</th>
                                <th class="py-2 pr-4">Tage</th>
                                <th class="py-2 pr-4">Klassen</th>
                                <th class="py-2 pr-4">Frist Lehrer</th>
                                <th class="py-2 pr-4">Pläne</th>
                                <th class="py-2 pr-4">Eltern-Info</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($liste as $s)
                                <tr class="hover:bg-gray-50">
                                    <td class="py-2 pr-4">
                                        <a href="{{ route('module.elternsprechtag.sprechtage.show', $s) }}" class="font-medium text-indigo-700 hover:underline">{{ $s->titel }}</a>
                                    </td>
                                    <td class="py-2 pr-4">{{ $s->tageText() ?: '–' }}</td>
                                    <td class="py-2 pr-4">{{ implode(', ', $s->klassenListe()) ?: '–' }}</td>
                                    <td class="py-2 pr-4 {{ $s->fristVorbei() ? 'text-gray-400' : '' }}">{{ $s->lehrer_frist?->format('d.m.Y') ?? '–' }}</td>
                                    <td class="py-2 pr-4">{{ $s->plaene_count }}</td>
                                    <td class="py-2 pr-4">
                                        @if ($s->info_versendet_am)
                                            <span class="text-green-700">✓ {{ $s->info_versendet_am->format('d.m.Y H:i') }}</span>
                                        @else
                                            <span class="text-gray-400">noch nicht</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-3 text-gray-400">Kein Elternsprechtag geplant.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endforeach
</x-app-layout>
