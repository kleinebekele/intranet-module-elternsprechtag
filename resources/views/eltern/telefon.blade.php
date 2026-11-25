{{-- Telefontermin wählen: Häkchen + Nummer. Erwartet: $nummer (Vorschlag), optional $an (bool, vorausgewählt). --}}
<div x-data="{ tel: @js((bool) ($an ?? false)) }" class="flex flex-wrap items-center gap-2 text-sm">
    <label class="flex items-center gap-1.5 text-gray-700">
        <input type="checkbox" x-model="tel" class="rounded border-gray-300">
        <i class="bx bx-phone"></i> als Telefontermin
    </label>
    <template x-if="tel">
        <input type="tel" name="telefon" required maxlength="30" value="{{ $nummer }}"
               placeholder="Ihre Telefonnummer" title="Unter dieser Nummer ruft die Lehrkraft Sie an."
               class="w-48 rounded-lg border-gray-300 py-1 text-sm">
    </template>
</div>
