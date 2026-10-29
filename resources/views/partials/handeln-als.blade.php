{{-- „Handeln als" für die Verwaltung: $personen (id, name), $param ('lehrer'|'eltern'), $aktuell, $aktion (URL ohne Query) --}}
<form method="GET" action="{{ $aktion }}"
      class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm">
    <span class="font-medium text-amber-900">Handeln als</span>
    @include('elternsprechtag::partials.suchauswahl', [
        'name' => $param,
        'optionen' => collect([['wert' => '', 'text' => '– mich selbst –']])
            ->concat($personen->map(fn ($p) => ['wert' => (string) $p->id, 'text' => \Intranet\Modules\Elternsprechtag\Support\Namen::anzeige($p->name)]))->all(),
        'wert' => $aktuell->is(auth()->user()) ? '' : (string) $aktuell->id,
        'leer' => 'Name suchen …',
        'absenden' => true,
        'breite' => 'w-72',
    ])
    @unless ($aktuell->is(auth()->user()))
        <span class="text-amber-900">Du handelst gerade als <strong>{{ $aktuell->name }}</strong>.</span>
    @endunless
</form>
