<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-gray-800">{{ $sprechtag->exists ? 'Elternsprechtag bearbeiten' : 'Neuer Elternsprechtag' }}</h1>
    </x-slot>

    @include('elternsprechtag::partials.meldung')

    <form method="POST"
          action="{{ $sprechtag->exists ? route('module.elternsprechtag.sprechtage.update', $sprechtag) : route('module.elternsprechtag.sprechtage.store') }}"
          x-data="{ tage: @js(old('tage', $tage)) }"
          class="grid grid-cols-1 gap-4 xl:grid-cols-2">
        @csrf
        @if ($sprechtag->exists) @method('PUT') @endif

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="text-base font-semibold text-gray-800">Allgemein</h2>
            <div class="mt-3 space-y-3">
                <label class="block text-sm"><span class="text-gray-600">Titel</span>
                    <input type="text" name="titel" required maxlength="255" value="{{ old('titel', $sprechtag->titel) }}"
                           placeholder="z. B. Elternsprechtag Herbst 2026" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                <label class="block text-sm"><span class="text-gray-600">Lehrer tragen ihren Terminplan ein bis (einschließlich)</span>
                    <input type="date" name="lehrer_frist" value="{{ old('lehrer_frist', $sprechtag->lehrer_frist?->toDateString()) }}"
                           class="mt-1 w-full rounded-lg border-gray-300 text-sm"></label>
                <label class="block text-sm"><span class="text-gray-600">Hinweis für die Eltern (steht in der Info-Mail)</span>
                    <textarea name="hinweis" rows="4" maxlength="5000" class="mt-1 w-full rounded-lg border-gray-300 text-sm"
                              placeholder="z. B. Die Terminbuchung öffnet am … im Intranet.">{{ old('hinweis', $sprechtag->hinweis) }}</textarea></label>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="text-base font-semibold text-gray-800">Tage</h2>
            <p class="mt-1 text-sm text-gray-500">Start und Ende sind der Vorschlag, mit dem der Terminplan jedes Lehrers beginnt.</p>
            <div class="mt-3 space-y-2">
                <template x-for="(tag, i) in tage" :key="i">
                    <div class="flex flex-wrap items-end gap-2">
                        <input type="hidden" :name="`tage[${i}][id]`" :value="tag.id ?? ''">
                        <label class="block text-sm"><span class="text-gray-600">Datum</span>
                            <input type="date" :name="`tage[${i}][datum]`" x-model="tag.datum" required class="mt-1 rounded-lg border-gray-300 text-sm"></label>
                        <label class="block text-sm"><span class="text-gray-600">von</span>
                            <input type="time" :name="`tage[${i}][von]`" x-model="tag.von" class="mt-1 rounded-lg border-gray-300 text-sm"></label>
                        <label class="block text-sm"><span class="text-gray-600">bis</span>
                            <input type="time" :name="`tage[${i}][bis]`" x-model="tag.bis" class="mt-1 rounded-lg border-gray-300 text-sm"></label>
                        <button type="button" x-show="tage.length > 1" @click="tage.splice(i, 1)"
                                class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50" title="Tag entfernen">✕</button>
                    </div>
                </template>
            </div>
            <button type="button" @click="tage.push({ id: null, datum: '', von: tage.at(-1)?.von ?? '08:00', bis: tage.at(-1)?.bis ?? '15:00' })"
                    class="mt-3 rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">+ weiterer Tag</button>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 xl:col-span-2" x-data>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-gray-800">Beteiligte Klassen</h2>
                <div class="flex gap-2 text-xs">
                    <button type="button" class="text-indigo-700 hover:underline" @click="$root.querySelectorAll('input[name=\'klassen[]\']').forEach(c => c.checked = true)">alle</button>
                    <button type="button" class="text-indigo-700 hover:underline" @click="$root.querySelectorAll('input[name=\'klassen[]\']').forEach(c => c.checked = false)">keine</button>
                </div>
            </div>
            @if ($alleKlassen === [])
                <p class="mt-2 text-sm text-amber-700">Keine Klassen gefunden – sie kommen aus dem Linear-Abgleich (Rollen „Lehrer Klasse …").</p>
            @else
                @php
                    $gewaehlt = old('klassen', $sprechtag->klassenListe());
                @endphp
                <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-6 lg:grid-cols-8 2xl:grid-cols-12">
                    @foreach ($alleKlassen as $k)
                        <label class="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm hover:bg-gray-50">
                            <input type="checkbox" name="klassen[]" value="{{ $k }}" @checked(in_array($k, $gewaehlt, true)) class="rounded border-gray-300">
                            <span>{{ $k }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="flex gap-2 xl:col-span-2">
            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">Speichern</button>
            <a href="{{ $sprechtag->exists ? route('module.elternsprechtag.sprechtage.show', $sprechtag) : route('module.elternsprechtag.sprechtage.index') }}"
               class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Abbrechen</a>
        </div>
    </form>
</x-app-layout>
