{{--
    Auswahlfeld mit Suche: Tippen filtert die Liste sofort.
    $name      – Name des Feldes im Formular
    $optionen  – Liste von ['wert' => …, 'text' => …, 'gruppe' => … (optional)]
    $wert      – vorausgewählter Wert (optional)
    $leer      – Platzhalter, wenn nichts gewählt ist
    $absenden  – Formular nach der Wahl sofort abschicken (optional)
    $breite    – Tailwind-Breite des Feldes (optional)
--}}
<div x-data="suchauswahl(@js(array_values($optionen)), @js((string) ($wert ?? '')), @js((bool) ($absenden ?? false)))"
     @click.outside="schliessen()" class="relative {{ $breite ?? 'w-64' }}">
    <input type="hidden" name="{{ $name }}" :value="wert">
    <input type="text" x-ref="feld" autocomplete="off" placeholder="{{ $leer ?? 'Bitte wählen …' }}"
           :value="offen ? suche : anzeige()"
           @focus="oeffnen()" @click="oeffnen()" @input="suche = $event.target.value; aktiv = 0; offen = true"
           @keydown.down.prevent="weiter(1)" @keydown.up.prevent="weiter(-1)"
           @keydown.enter.prevent="waehlen(treffer()[aktiv])" @keydown.escape="schliessen()" @keydown.tab="schliessen()"
           class="w-full rounded-lg border-gray-300 pr-8 text-sm">
    <i class="bx bx-search pointer-events-none absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400"></i>
    <div x-show="offen" x-cloak class="absolute z-40 mt-1 max-h-72 w-full min-w-56 overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
        <template x-for="(o, i) in treffer()" :key="o.wert">
            <div>
                <div x-show="o.gruppe && (i === 0 || treffer()[i - 1].gruppe !== o.gruppe)"
                     class="px-3 pb-0.5 pt-2 text-xs font-semibold uppercase tracking-wide text-gray-500" x-text="o.gruppe"></div>
                <div role="option" @mousedown.prevent="waehlen(o)" @mouseenter="aktiv = i"
                     class="cursor-pointer px-3 py-1.5 text-sm"
                     :class="{ 'bg-indigo-50 text-indigo-900': i === aktiv, 'font-medium': o.wert === wert }" x-text="o.text"></div>
            </div>
        </template>
        <div x-show="treffer().length === 0" class="px-3 py-2 text-sm text-gray-400">Nichts gefunden.</div>
    </div>
</div>

@once
    <script>
        function suchauswahl(optionen, wert, absenden) {
            const norm = (s) => String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

            return {
                optionen, wert, offen: false, suche: '', aktiv: 0,
                anzeige() { return this.optionen.find(o => String(o.wert) === String(this.wert))?.text ?? ''; },
                treffer() {
                    const w = norm(this.suche).split(/\s+/).filter(Boolean);
                    return this.optionen.filter(o => w.every(t => norm(o.text + ' ' + (o.gruppe ?? '')).includes(t)));
                },
                oeffnen() {
                    if (this.offen) return;
                    this.offen = true; this.suche = '';
                    this.aktiv = Math.max(0, this.treffer().findIndex(o => String(o.wert) === String(this.wert)));
                },
                schliessen() { this.offen = false; this.suche = ''; },
                weiter(n) {
                    if (! this.offen) return this.oeffnen();
                    const max = this.treffer().length - 1;
                    this.aktiv = Math.min(max, Math.max(0, this.aktiv + n));
                },
                waehlen(o) {
                    if (! o) return;
                    this.wert = String(o.wert);
                    this.schliessen();
                    this.$refs.feld.blur();
                    if (absenden) this.$nextTick(() => this.$root.closest('form').submit());
                },
            };
        }
    </script>
@endonce
