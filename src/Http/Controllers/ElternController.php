<?php

namespace Intranet\Modules\Elternsprechtag\Http\Controllers;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Models\Termin;
use Intranet\Modules\Elternsprechtag\Support\Assistent;
use Intranet\Modules\Elternsprechtag\Support\Buchung;
use Intranet\Modules\Elternsprechtag\Support\Klassen;
use Intranet\Modules\Elternsprechtag\Support\Namen;
use Intranet\Modules\Elternsprechtag\Support\Rechte;

/**
 * Seite der Eltern: Gesprächstermine bei den Lehrern ihrer Kinder buchen.
 * Die Verwaltung kann über `?eltern=` für ein Elternteil handeln (Telefonbuchung).
 */
class ElternController
{
    public function index(Request $request): View
    {
        $eltern = $this->eltern($request);
        $verwaltung = Rechte::istVerwaltung($request->user());

        $sprechtage = Sprechtag::with('tage')->get()
            ->reject->vorbei()
            ->sortBy(fn (Sprechtag $s) => $s->tage->first()?->datum)
            ->map(fn (Sprechtag $s) => ['sprechtag' => $s, 'kinder' => Buchung::angebot($eltern, $s)])
            ->filter(fn (array $z) => $z['kinder']->isNotEmpty())
            ->values();

        return view('elternsprechtag::eltern.index', [
            'eltern' => $eltern,
            'fremd' => ! $eltern->is($request->user()),
            'verwaltung' => $verwaltung,
            'sprechtage' => $sprechtage,
            'vorschlaege' => $sprechtage->mapWithKeys(fn ($z) => [$z['sprechtag']->id => session($this->vorschlagSchluessel($z['sprechtag'], $eltern))]),
            'personen' => $verwaltung ? $this->alleEltern() : collect(),
        ]);
    }

    public function buchen(Request $request, Sprechtag $sprechtag): RedirectResponse
    {
        $eltern = $this->eltern($request);
        $verwaltung = Rechte::istVerwaltung($request->user());

        $daten = $request->validate([
            'plan_id' => ['required', 'integer'],
            'kind_id' => ['required', 'integer'],
            'slot' => ['required', 'string', 'regex:/^\d+\|\d{2}:\d{2}$/'],
        ], ['slot.required' => 'Bitte eine Uhrzeit auswählen.']);

        if (! $verwaltung && ! Buchung::offen($sprechtag)) {
            return $this->zurueck($request, $eltern)->with('error', 'Die Termine sind noch nicht veröffentlicht.');
        }

        [$tagId, $von] = explode('|', $daten['slot']);
        $ergebnis = $this->einenBuchen($request, $eltern, $sprechtag, (int) $daten['plan_id'], (int) $daten['kind_id'], (int) $tagId, $von);
        abort_if($ergebnis === null, 404);

        return $this->zurueck($request, $eltern)->with($ergebnis[0] ? 'status' : 'error', $ergebnis[1]);
    }

    /**
     * Termin-Assistent: aus Zeitfenster(n) und Prioritäten einen Vorschlag
     * berechnen. Der Vorschlag liegt in der Sitzung, bis er gebucht oder
     * verworfen wird; gebucht wird über buchen() bzw. vorschlagBuchen().
     */
    public function assistent(Request $request, Sprechtag $sprechtag): RedirectResponse
    {
        $eltern = $this->eltern($request);
        $sprechtag->load('tage');

        $daten = $request->validate([
            'tage' => ['array'],
            'tage.*.dabei' => ['nullable', 'boolean'],
            'tage.*.von' => ['nullable', 'date_format:H:i'],
            'tage.*.bis' => ['nullable', 'date_format:H:i'],
            'prio' => ['array'],
            'prio.*' => ['nullable', 'integer', 'between:0,3'],
            'abstand' => ['nullable', 'integer', 'between:0,30'],
            'verwerfen' => ['nullable', 'boolean'],
        ]);
        $schluessel = $this->vorschlagSchluessel($sprechtag, $eltern);

        if ($request->boolean('verwerfen')) {
            session()->forget($schluessel);

            return $this->zurueck($request, $eltern);
        }

        // Ohne Haken an einem Tag sind alle Tage erlaubt; ohne Uhrzeit der ganze Tag.
        $gewaehlteTage = collect($daten['tage'] ?? [])->filter(fn ($t) => ! empty($t['dabei']));
        $fenster = fn (int $tagId) => $gewaehlteTage->isEmpty() ? [null, null]
            : ($gewaehlteTage->has($tagId) ? [$gewaehlteTage[$tagId]['von'] ?? null, $gewaehlteTage[$tagId]['bis'] ?? null] : false);

        $wuensche = [];
        foreach (Buchung::angebot($eltern, $sprechtag) as $z) {
            foreach ($z['lehrer'] as $l) {
                $key = $l['plan']->id.'-'.$z['kind']->id;
                $prio = (int) ($daten['prio'][$key] ?? 0);
                if ($prio < 1 || $l['termin']) {
                    continue;
                }
                $slots = collect($l['slots'])->where('frei', true)->filter(function ($s) use ($fenster) {
                    $f = $fenster($s['tag_id']);
                    if ($f === false) {
                        return false;
                    }

                    return (! $f[0] || $s['von'] >= $f[0]) && (! $f[1] || $s['bis'] <= $f[1]);
                })->map(fn ($s) => array_intersect_key($s, array_flip(['tag_id', 'tag', 'von', 'bis'])))->values()->all();
                $wuensche[] = ['key' => $key, 'prio' => $prio, 'lehrer' => $l['plan']->user_id, 'slots' => $slots];
            }
        }

        if ($wuensche === []) {
            return $this->zurueck($request, $eltern, '#assistent-'.$sprechtag->id)->with('error', 'Bitte wählen Sie mindestens eine Lehrkraft mit Priorität aus.');
        }

        $ergebnis = Assistent::planen($wuensche, (int) ($daten['abstand'] ?? 0));
        session()->put($schluessel, $ergebnis + ['eingaben' => $request->only(['tage', 'prio', 'abstand'])]);

        return $this->zurueck($request, $eltern, '#assistent-'.$sprechtag->id);
    }

    /** Die angehakten Termine des Vorschlags buchen – was inzwischen vergeben ist, wird übersprungen und gemeldet. */
    public function vorschlagBuchen(Request $request, Sprechtag $sprechtag): RedirectResponse
    {
        $eltern = $this->eltern($request);
        if (! Rechte::istVerwaltung($request->user()) && ! Buchung::offen($sprechtag)) {
            return $this->zurueck($request, $eltern)->with('error', 'Die Termine sind noch nicht veröffentlicht.');
        }

        $vorschlag = session($this->vorschlagSchluessel($sprechtag, $eltern));
        abort_if(! $vorschlag || $vorschlag['plan'] === [], 404);

        $auswahl = array_map('intval', (array) $request->input('auswahl', []));
        if ($auswahl === []) {
            return $this->zurueck($request, $eltern, '#assistent-'.$sprechtag->id)->with('error', 'Bitte haken Sie mindestens einen Termin an.');
        }

        $gebucht = 0;
        $fehler = [];
        foreach ($vorschlag['plan'] as $nr => $p) {
            if (! in_array($nr, $auswahl, true)) {
                continue;
            }
            [$planId, $kindId] = array_map('intval', explode('-', $p['key']));
            // Schon gebucht (z. B. zweimal abgeschickt)? Dann nicht noch einmal anfassen.
            if (Termin::where(['plan_id' => $planId, 'kind_user_id' => $kindId, 'tag_id' => (int) $p['slot']['tag_id'], 'von' => $p['slot']['von']])->exists()) {
                continue;
            }
            $ergebnis = $this->einenBuchen($request, $eltern, $sprechtag, $planId, $kindId, (int) $p['slot']['tag_id'], $p['slot']['von']);
            if ($ergebnis && $ergebnis[0]) {
                $gebucht++;
            } else {
                $fehler[] = $p['slot']['von'].' Uhr: '.($ergebnis[1] ?? 'nicht mehr möglich');
            }
        }

        session()->forget($this->vorschlagSchluessel($sprechtag, $eltern));

        $antwort = $this->zurueck($request, $eltern)->with('status', $gebucht === 1 ? 'Ein Termin gebucht.' : "{$gebucht} Termine gebucht.");

        return $fehler === [] ? $antwort
            : $antwort->with('error', 'Nicht gebucht – '.implode(' · ', $fehler).' Bitte starten Sie den Assistenten für die übrigen erneut.');
    }

    /**
     * Einen Termin buchen bzw. umbuchen – gemeinsame Regeln für den Knopf je
     * Lehrer und für „Alle buchen". null = gehört nicht zu diesem Elternteil.
     *
     * @return array{0: bool, 1: string}|null
     */
    private function einenBuchen(Request $request, User $eltern, Sprechtag $sprechtag, int $planId, int $kindId, int $tagId, string $von): ?array
    {
        $kind = Buchung::kinder($eltern, $sprechtag)->first(fn ($z) => $z['kind']->id === $kindId);
        $plan = Plan::with(['user.roles', 'tage.tag', 'termine'])
            ->where('sprechtag_id', $sprechtag->id)->find($planId);
        if ($kind === null || $plan === null || $plan->user === null || $plan->abgesagt()
            || array_intersect($kind['klassen'], Plan::klassenVon($plan, $plan->user, $sprechtag)) === []) {
            return null;
        }

        $alt = $plan->termine->firstWhere('kind_user_id', $kind['kind']->id);
        $andere = Buchung::familienTermine(Buchung::kinder($eltern, $sprechtag)->pluck('kind.id')->all(), $sprechtag)
            ->reject(fn (Termin $t) => $alt && $t->id === $alt->id);
        $slot = collect(Buchung::slots($plan, $andere))->first(fn ($s) => $s['tag_id'] === $tagId && $s['von'] === $von);

        if ($slot === null || (! $slot['frei'] && ! ($alt && (int) $alt->tag_id === $tagId && $alt->von === $von))) {
            return [false, 'Diese Uhrzeit ist nicht mehr frei oder überschneidet sich mit einem anderen Ihrer Termine.'];
        }

        try {
            DB::transaction(function () use ($alt, $plan, $tagId, $slot, $kind, $eltern, $request) {
                $alt?->delete();
                Termin::create([
                    'plan_id' => $plan->id,
                    'tag_id' => $tagId,
                    'von' => $slot['von'],
                    'bis' => $slot['bis'],
                    'kind_user_id' => $kind['kind']->id,
                    'eltern_user_id' => $eltern->id,
                    'gebucht_von' => $request->user()->id,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return [false, 'Diese Uhrzeit wurde gerade vergeben. Bitte eine andere wählen.'];
        }

        $this->audit('elternsprechtag.termin', $sprechtag, $eltern, [
            'lehrer' => $plan->user_id, 'kind' => $kind['kind']->id, 'slot' => $tagId.'|'.$von, 'umgebucht' => $alt !== null,
        ]);

        return [true, 'Termin bei '.$plan->user->name.' gebucht: '.$slot['tag'].', '.$slot['von'].' Uhr.'];
    }

    private function vorschlagSchluessel(Sprechtag $sprechtag, User $eltern): string
    {
        return 'elternsprechtag.vorschlag.'.$sprechtag->id.'.'.$eltern->id;
    }

    public function absagen(Request $request, Termin $termin): RedirectResponse
    {
        $eltern = $this->eltern($request);
        $termin->load(['plan.sprechtag', 'plan.user', 'tag']);
        $sprechtag = $termin->plan->sprechtag;

        $eigen = Buchung::kinder($eltern, $sprechtag)->contains(fn ($z) => $z['kind']->id === (int) $termin->kind_user_id);
        abort_unless($eigen, 404);

        if (! Rechte::istVerwaltung($request->user()) && $termin->beginn()->isPast()) {
            return $this->zurueck($request, $eltern)->with('error', 'Der Termin hat schon begonnen.');
        }

        $text = $termin->beschriftung();
        $termin->delete();
        $this->audit('elternsprechtag.absage', $sprechtag, $eltern, ['lehrer' => $termin->plan->user_id, 'kind' => $termin->kind_user_id]);

        return $this->zurueck($request, $eltern)->with('status', 'Termin abgesagt: '.$text.'.');
    }

    /** Wer bucht? Man selbst – oder für die Verwaltung das Elternteil aus `?eltern=`. */
    private function eltern(Request $request): User
    {
        $user = $request->user();
        if ($request->filled('eltern') && (int) $request->input('eltern') !== $user->id) {
            abort_unless(Rechte::istVerwaltung($user), 403);

            return User::findOrFail((int) $request->input('eltern'));
        }

        return $user;
    }

    /** Alle, die mit einem Kind verknüpft sind oder eine Linear-Elternrolle haben. */
    private function alleEltern()
    {
        return User::query()
            ->where(fn ($q) => $q->whereHas('children')
                ->orWhereHas('roles', fn ($r) => $r->where('roles.role_id', 'like', 'linear-klasse-%-eltern')))
            ->get(['id', 'name'])
            ->sortBy(fn (User $u) => Namen::schluessel($u->name))
            ->values();
    }

    private function zurueck(Request $request, User $eltern, string $anker = ''): RedirectResponse
    {
        return redirect()->to(route('module.elternsprechtag.eltern.index', $eltern->is($request->user()) ? [] : ['eltern' => $eltern->id]).$anker);
    }

    private function audit(string $aktion, Sprechtag $sprechtag, User $eltern, array $daten): void
    {
        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben($aktion, $sprechtag->titel, betroffener: $eltern,
                daten: ['sprechtag' => $sprechtag->id] + $daten, ziel: "Elternsprechtag {$sprechtag->id}");
        }
    }
}
