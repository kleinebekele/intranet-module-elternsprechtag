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
    {{-- Die Anleitung, die diese Personen selbst haben – zum Erklären am Telefon. --}}
    @php $anleitung = $param === 'eltern' ? 'module.elternsprechtag.sprechtage.elterninfo' : 'module.elternsprechtag.sprechtage.lehrerinfo'; @endphp
    @if (\Illuminate\Support\Facades\Route::has($anleitung))
        <a href="{{ route($anleitung) }}" target="_blank" rel="noopener" class="ml-auto inline-flex items-center gap-1 text-amber-900 underline hover:text-amber-700">
            <i class="bx bx-file"></i> Anleitung für {{ $param === 'eltern' ? 'Eltern' : 'Lehrkräfte' }} (PDF)
        </a>
    @endif
</form>
