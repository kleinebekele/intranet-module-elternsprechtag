{{-- „Handeln als" für die Verwaltung: $personen (id, name), $param ('lehrer'|'eltern'), $aktuell, $aktion (URL ohne Query) --}}
<form method="GET" action="{{ $aktion }}"
      class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm">
    <label for="handeln-als" class="font-medium text-amber-900">Handeln als</label>
    <select id="handeln-als" name="{{ $param }}" onchange="this.form.submit()" class="min-w-64 rounded-lg border-gray-300 text-sm">
        <option value="">– mich selbst –</option>
        @foreach ($personen as $p)
            <option value="{{ $p->id }}" @selected($aktuell->id === $p->id && ! $aktuell->is(auth()->user()))>{{ $p->name }}</option>
        @endforeach
    </select>
    <noscript><button type="submit" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5">Wechseln</button></noscript>
    @unless ($aktuell->is(auth()->user()))
        <span class="text-amber-900">Du handelst gerade als <strong>{{ $aktuell->name }}</strong>.</span>
    @endunless
</form>
