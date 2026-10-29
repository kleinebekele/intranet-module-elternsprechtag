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

        $kind = Buchung::kinder($eltern, $sprechtag)->first(fn ($z) => $z['kind']->id === (int) $daten['kind_id']);
        $plan = Plan::with(['user.roles', 'tage.tag', 'termine'])
            ->where('sprechtag_id', $sprechtag->id)->find((int) $daten['plan_id']);
        abort_if($kind === null || $plan === null || $plan->user === null || $plan->abgesagt(), 404);
        abort_unless(array_intersect($kind['klassen'], Plan::klassenVon($plan, $plan->user, $sprechtag)) !== [], 404);

        [$tagId, $von] = explode('|', $daten['slot']);
        $tagId = (int) $tagId;

        $alt = $plan->termine->firstWhere('kind_user_id', $kind['kind']->id);
        $andere = Buchung::familienTermine(Buchung::kinder($eltern, $sprechtag)->pluck('kind.id')->all(), $sprechtag)
            ->reject(fn (Termin $t) => $alt && $t->id === $alt->id);
        $slot = collect(Buchung::slots($plan, $andere))->first(fn ($s) => $s['tag_id'] === $tagId && $s['von'] === $von);

        if ($slot === null || (! $slot['frei'] && ! ($alt && (int) $alt->tag_id === $tagId && $alt->von === $von))) {
            return $this->zurueck($request, $eltern)->with('error', 'Diese Uhrzeit ist nicht mehr frei oder überschneidet sich mit einem anderen Ihrer Termine.');
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
            return $this->zurueck($request, $eltern)->with('error', 'Diese Uhrzeit wurde gerade vergeben. Bitte eine andere wählen.');
        }

        $this->audit('elternsprechtag.termin', $sprechtag, $eltern, [
            'lehrer' => $plan->user_id, 'kind' => $kind['kind']->id, 'slot' => $daten['slot'], 'umgebucht' => $alt !== null,
        ]);

        return $this->zurueck($request, $eltern)->with('status', 'Termin bei '.$plan->user->name.' gebucht: '.$slot['tag'].', '.$slot['von'].' Uhr.');
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

    private function zurueck(Request $request, User $eltern): RedirectResponse
    {
        return redirect()->route('module.elternsprechtag.eltern.index', $eltern->is($request->user()) ? [] : ['eltern' => $eltern->id]);
    }

    private function audit(string $aktion, Sprechtag $sprechtag, User $eltern, array $daten): void
    {
        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben($aktion, $sprechtag->titel, betroffener: $eltern,
                daten: ['sprechtag' => $sprechtag->id] + $daten, ziel: "Elternsprechtag {$sprechtag->id}");
        }
    }
}
