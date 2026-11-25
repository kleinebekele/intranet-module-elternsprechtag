{{-- Nummer für Telefontermine – nur, wenn die Lehrkraft Uhrzeiten als Telefon angeboten hat.
     Erwartet: $nummer (Vorschlag). Pflicht prüft der Server, sobald eine Telefon-Uhrzeit gewählt ist. --}}
<label class="flex flex-wrap items-center gap-2 text-sm text-gray-700">
    <span class="flex items-center gap-1 text-sky-700"><i class="bx bx-phone"></i> Telefonnummer</span>
    <input type="tel" name="telefon" maxlength="30" value="{{ $nummer }}"
           placeholder="nur für Telefontermine" title="Bei einer Telefon-Uhrzeit ruft die Lehrkraft Sie unter dieser Nummer an."
           class="w-48 rounded-lg border-gray-300 py-1 text-sm">
</label>
