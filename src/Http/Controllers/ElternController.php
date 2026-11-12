<?php

namespace Intranet\Modules\Elternsprechtag\Http\Controllers;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Models\Termin;
use Intranet\Modules\Elternsprechtag\Support\Assistent;
use Intranet\Modules\Elternsprechtag\Support\Buchung;
use Intranet\Modules\Elternsprechtag\Support\Elternliste;
use Intranet\Modules\Elternsprechtag\Support\Klassen;
use Intranet\Modules\Elternsprechtag\Support\Lehrerinfo;
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

        // Eigenen Aufruf merken (Übersicht „Wunschgespräche" zeigt, wer schon da war).
        if ($eltern->is($request->user())) {
            foreach ($sprechtage as $z) {
                $jetzt = now();
                DB::table('elternsprechtag_besuche')->upsert(
                    [['sprechtag_id' => $z['sprechtag']->id, 'user_id' => $eltern->id, 'erster_besuch_am' => $jetzt, 'letzter_besuch_am' => $jetzt]],
                    ['sprechtag_id', 'user_id'],
                    ['letzter_besuch_am'],
                );
            }
        }

        return view('elternsprechtag::eltern.index', [
            'eltern' => $eltern,
            'fremd' => ! $eltern->is($request->user()),
            'verwaltung' => $verwaltung,
            'sprechtage' => $sprechtage,
            'vorschlaege' => $sprechtage->mapWithKeys(fn ($z) => [$z['sprechtag']->id => session($this->vorschlagSchluessel($z['sprechtag'], $eltern))]),
            'personen' => $verwaltung ? $this->alleEltern() : collect(),
            'keineZeit' => DB::table('elternsprechtag_besuche')->where('user_id', $eltern->id)->whereNotNull('keine_zeit_am')->get()->keyBy('sprechtag_id'),
        ]);
    }

    /**
     * Freie Uhrzeiten eines Gesprächs (JSON) – für die Sprechtag-Übersicht, wo
     * die Verwaltung ein Wunschgespräch direkt aus der Liste heraus bucht.
     * `plan` darf irgendein Plan des (gemeinsamen) Gesprächs sein.
     */
    public function zeiten(Request $request, Sprechtag $sprechtag): \Illuminate\Http\JsonResponse
    {
        abort_unless(Rechte::istVerwaltung($request->user()), 403);
        $eltern = $this->eltern($request);
        $kindId = (int) $request->query('kind');
        $planId = (int) $request->query('plan');

        $eintrag = Buchung::angebot($eltern, $sprechtag)->first(fn ($z) => $z['kind']->id === $kindId)['lehrer']
            ?->first(fn ($l) => $l['plan']->id === $planId || ($l['team'] && $l['team']->contains('id', $planId)));
        abort_if($eintrag === null, 404);

        return response()->json([
            'namen' => $eintrag['namen'],
            'team' => (bool) $eintrag['team'],
            'ausweichen' => $eintrag['ausweichen'],
            'termin' => $eintrag['termin'] ? $eintrag['termin']->loadMissing('tag')->beschriftung() : null,
            'plan_id' => $eintrag['plan']->id,
            'slots' => collect($eintrag['slots'])->where('frei', true)->map(fn ($sl) => [
                'wert' => $sl['tag_id'].'|'.$sl['von'].(isset($sl['plan']) ? '|'.$sl['plan'] : ''),
                'text' => $sl['von'].'–'.$sl['bis'].' Uhr'.(isset($sl['nur']) ? ' (nur '.$sl['nur'].')' : ''),
                'tag' => $sl['tag'],
            ])->values(),
        ]);
    }

    /** Terminliste der Familie als PDF – im Browser ansehen bzw. mit ?download=1 herunterladen. */
    public function liste(Request $request, Sprechtag $sprechtag)
    {
        $eltern = $this->eltern($request);
        abort_unless(Buchung::kinder($eltern, $sprechtag)->isNotEmpty(), 404);

        return response(Elternliste::pdf($eltern, $sprechtag), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.Elternliste::dateiname($sprechtag).'"',
        ]);
    }

    /** Terminliste der Familie per Mail an das Elternteil schicken. */
    public function listeMailen(Request $request, Sprechtag $sprechtag): RedirectResponse
    {
        $eltern = $this->eltern($request);
        abort_unless(Buchung::kinder($eltern, $sprechtag)->isNotEmpty(), 404);

        return $this->zurueck($request, $eltern)->with(...(Elternliste::senden($eltern, $sprechtag)
            ? ['status', 'Ihre Terminliste ist unterwegs an '.$eltern->email.'.']
            : ['error', 'Für dieses Konto ist keine Mailadresse hinterlegt.']));
    }

    /**
     * „Ich kann diesmal keinen Termin wahrnehmen" melden bzw. zurücknehmen –
     * die Eltern selbst oder die Verwaltung nach einem Anruf.
     */
    public function keineZeit(Request $request, Sprechtag $sprechtag): RedirectResponse
    {
        $eltern = $this->eltern($request);
        abort_unless(Buchung::kinder($eltern, $sprechtag)->isNotEmpty(), 404);
        $daten = $request->validate(['melden' => ['required', 'boolean'], 'bemerkung' => ['nullable', 'string', 'max:1000']]);

        $melden = (bool) $daten['melden'];
        $werte = $melden
            ? ['keine_zeit_am' => now(), 'keine_zeit_bemerkung' => trim((string) ($daten['bemerkung'] ?? '')) ?: null]
            : ['keine_zeit_am' => null, 'keine_zeit_bemerkung' => null];
        DB::table('elternsprechtag_besuche')->updateOrInsert(['sprechtag_id' => $sprechtag->id, 'user_id' => $eltern->id], $werte);
        $this->audit('elternsprechtag.keine_zeit', $sprechtag, $eltern, ['gemeldet' => $melden]);

        return $this->zurueck($request, $eltern, '#keine-zeit-'.$sprechtag->id)->with('status', $melden
            ? 'Danke für Ihre Rückmeldung – wir wissen jetzt, dass Sie diesmal keinen Termin wahrnehmen können.'
            : 'Ihre Meldung „keine Zeit" ist zurückgenommen.');
    }

    public function buchen(Request $request, Sprechtag $sprechtag): RedirectResponse
    {
        $eltern = $this->eltern($request);
        $verwaltung = Rechte::istVerwaltung($request->user());

        $daten = $request->validate([
            'plan_id' => ['required', 'integer'],
            'kind_id' => ['required', 'integer'],
            'slot' => ['required', 'string', 'regex:/^\d+\|\d{2}:\d{2}(\|\d+)?$/'],
        ], ['slot.required' => 'Bitte eine Uhrzeit auswählen.']);

        if (! $verwaltung && ! Buchung::offen($sprechtag, $eltern)) {
            return $this->zurueck($request, $eltern)->with('error', 'Die Buchung ist für Sie noch nicht geöffnet.');
        }

        [$tagId, $von, $nurPlan] = explode('|', $daten['slot']) + [2 => null];
        $ergebnis = $this->einenBuchen($request, $eltern, $sprechtag, (int) $daten['plan_id'], (int) $daten['kind_id'], (int) $tagId, $von, $nurPlan !== null ? (int) $nurPlan : null);
        abort_if($ergebnis === null, 404);

        // Gebucht aus der Sprechtag-Übersicht (Wunschgespräche) → dorthin zurück.
        if ($verwaltung && $request->input('zurueck') === 'sprechtag') {
            return redirect()->to(route('module.elternsprechtag.sprechtage.show', $sprechtag).'#wunschgespraeche')
                ->with('elternsprechtag_wunsch_offen', true)
                ->with($ergebnis[0] ? 'status' : 'error', $ergebnis[1]);
        }

        return $this->zurueckManuell($request, $eltern, $sprechtag)->with($ergebnis[0] ? 'status' : 'error', $ergebnis[1]);
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

        $verwaltung = Rechte::istVerwaltung($request->user());
        $wuensche = [];
        $gruende = [];
        foreach (Buchung::angebot($eltern, $sprechtag) as $z) {
            foreach ($z['lehrer'] as $l) {
                $key = $l['plan']->id.'-'.$z['kind']->id;
                $prio = (int) ($daten['prio'][$key] ?? 0);
                if ($prio < 1 || $l['termin'] || ($l['gesperrt'] && ! $verwaltung)) {
                    continue;
                }
                $imFenster = collect($l['slots'])->filter(function ($s) use ($fenster) {
                    $f = $fenster($s['tag_id']);
                    if ($f === false) {
                        return false;
                    }

                    return (! $f[0] || $s['von'] >= $f[0]) && (! $f[1] || $s['bis'] <= $f[1]);
                });
                $slots = $imFenster->where('frei', true)
                    ->map(fn ($s) => array_intersect_key($s, array_flip(['tag_id', 'tag', 'von', 'bis', 'plan', 'nur'])))->values()->all();
                $wuensche[] = ['key' => $key, 'prio' => $prio, 'lehrer' => $l['plan']->user_id, 'slots' => $slots];

                // Warum es bei dieser Lehrkraft nicht klappen kann – für die Anzeige, falls sie offen bleibt.
                $familie = $imFenster->where('familie', true);
                $gruende[$key] = $slots !== [] ? ['grund' => 'kollision']
                    : ($familie->isNotEmpty() ? ['grund' => 'familie', 'zeiten' => $familie->map(fn ($s) => $s['tag'].' '.$s['von'])->unique()->values()->all()]
                    : (collect($l['slots'])->contains(fn ($s) => $s['frei'] || ($s['familie'] ?? false)) ? ['grund' => 'fenster'] : ['grund' => 'voll']));
            }
        }

        if ($wuensche === []) {
            return $this->zurueck($request, $eltern, '#assistent-'.$sprechtag->id)->with('error', 'Bitte wählen Sie mindestens eine Lehrkraft mit Priorität aus.');
        }

        $ergebnis = Assistent::planen($wuensche, (int) ($daten['abstand'] ?? 0));
        $ergebnis['gruende'] = array_intersect_key($gruende, array_flip($ergebnis['offen']));
        session()->put($schluessel, $ergebnis + ['eingaben' => $request->only(['tage', 'prio', 'abstand'])]);

        return $this->zurueck($request, $eltern, '#assistent-'.$sprechtag->id);
    }

    /** Die angehakten Termine des Vorschlags buchen – was inzwischen vergeben ist, wird übersprungen und gemeldet. */
    public function vorschlagBuchen(Request $request, Sprechtag $sprechtag): RedirectResponse
    {
        $eltern = $this->eltern($request);
        if (! Rechte::istVerwaltung($request->user()) && ! Buchung::offen($sprechtag, $eltern)) {
            return $this->zurueck($request, $eltern)->with('error', 'Die Buchung ist für Sie noch nicht geöffnet.');
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
            $ergebnis = $this->einenBuchen($request, $eltern, $sprechtag, $planId, $kindId, (int) $p['slot']['tag_id'], $p['slot']['von'], $p['slot']['plan'] ?? null);
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
     * Lehrer und für den Assistenten. Gebucht wird nur, was Buchung::angebot()
     * dem Elternteil anbietet; bei einem gemeinsamen Gespräch der Klassenlehrer
     * entsteht je Plan ein Termin mit derselben Gruppe. Ist dort keine
     * gemeinsame Uhrzeit mehr frei, nennt $nurPlan den Klassenlehrer, bei dem
     * allein gebucht wird. null = gehört nicht zu diesem Elternteil.
     *
     * @return array{0: bool, 1: string}|null
     */
    private function einenBuchen(Request $request, User $eltern, Sprechtag $sprechtag, int $planId, int $kindId, int $tagId, string $von, ?int $nurPlan = null): ?array
    {
        $eintrag = Buchung::angebot($eltern, $sprechtag)->first(fn ($z) => $z['kind']->id === $kindId)['lehrer']
            ?->first(fn ($l) => $l['plan']->id === $planId);
        if ($eintrag === null) {
            return null;
        }
        if ($eintrag['gesperrt'] && ! Rechte::istVerwaltung($request->user())) {
            return [false, 'Bei '.$eintrag['namen'].' können Sie erst buchen, wenn alle Gespräche freigegeben sind.'];
        }

        $alle = $eintrag['team'] ?? collect([$eintrag['plan']]);
        $alt = Termin::whereIn('plan_id', $alle->pluck('id'))->where('kind_user_id', $kindId)->get();
        $slot = collect($eintrag['slots'])->first(fn ($s) => $s['tag_id'] === $tagId && $s['von'] === $von && ($s['plan'] ?? null) === $nurPlan);
        $allein = $slot !== null && isset($slot['plan']);
        $plaene = $allein ? $alle->where('id', $slot['plan'])->values() : $alle;
        $gleich = $alt->count() === $plaene->count() && $alt->every(fn (Termin $t) => (int) $t->tag_id === $tagId && $t->von === $von);

        if ($slot === null || (! $slot['frei'] && ! $gleich)) {
            return [false, 'Diese Uhrzeit ist nicht mehr frei oder überschneidet sich mit einem anderen Ihrer Termine.'];
        }

        try {
            DB::transaction(function () use ($alt, $plaene, $tagId, $slot, $kindId, $eltern, $request, $allein) {
                // Auch die Gruppen-Partner eines alten gemeinsamen Termins mitnehmen.
                $alt->each(fn (Termin $t) => $t->absagenMitGruppe());
                $gruppe = $plaene->count() > 1 ? (string) Str::uuid() : null;
                foreach ($plaene as $plan) {
                    Termin::create([
                        'plan_id' => $plan->id,
                        'tag_id' => $tagId,
                        'von' => $slot['von'],
                        'bis' => $slot['bis'],
                        'kind_user_id' => $kindId,
                        'eltern_user_id' => $eltern->id,
                        'gebucht_von' => $request->user()->id,
                        'gruppe' => $gruppe,
                        'allein' => $allein,
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            return [false, 'Diese Uhrzeit wurde gerade vergeben. Bitte eine andere wählen.'];
        }

        Lehrerinfo::pruefen($plaene->pluck('id')->merge($alt->pluck('plan_id')));

        $this->audit('elternsprechtag.termin', $sprechtag, $eltern, [
            'lehrer' => $plaene->pluck('user_id')->all(), 'kind' => $kindId, 'slot' => $tagId.'|'.$von, 'umgebucht' => $alt->isNotEmpty(), 'allein' => $allein,
        ]);

        return [true, 'Termin bei '.($allein ? $slot['nur'] : $eintrag['namen']).' gebucht: '.$slot['tag'].', '.$slot['von'].' Uhr.'];
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
            return $this->zurueckManuell($request, $eltern, $sprechtag)->with('error', 'Der Termin hat schon begonnen.');
        }
        if (! Rechte::istVerwaltung($request->user()) && $sprechtag->buchungGeschlossen()) {
            return $this->zurueckManuell($request, $eltern, $sprechtag)->with('error', 'Die Terminbuchung ist geschlossen. Bitte sagen Sie direkt bei der Lehrkraft ab.');
        }

        $text = $termin->beschriftung();
        $gruppe = $termin->gruppeTermine();
        $lehrer = $gruppe->pluck('plan.user_id')->all();
        $ohneMail = Rechte::istVerwaltung($request->user()) && $request->boolean('ohne_mail');
        $termin->absagenMitGruppe();
        Lehrerinfo::pruefen($gruppe->pluck('plan_id'), ! $ohneMail);
        $this->audit('elternsprechtag.absage', $sprechtag, $eltern, ['lehrer' => $lehrer, 'kind' => $termin->kind_user_id, 'ohne_mail' => $ohneMail]);

        return $this->zurueckManuell($request, $eltern, $sprechtag)->with('status', 'Termin abgesagt: '.$text.'.'.($ohneMail ? ' Es wurde keine Mail verschickt.' : ''));
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

    /** Wie zurueck(), aber die Seite öffnet wieder auf dem Reiter „Manuelle Termine". */
    private function zurueckManuell(Request $request, User $eltern, Sprechtag $sprechtag): RedirectResponse
    {
        return $this->zurueck($request, $eltern)->with('elternsprechtag_reiter', $sprechtag->id);
    }

    private function audit(string $aktion, Sprechtag $sprechtag, User $eltern, array $daten): void
    {
        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben($aktion, $sprechtag->titel, betroffener: $eltern,
                daten: ['sprechtag' => $sprechtag->id] + $daten, ziel: "Elternsprechtag {$sprechtag->id}");
        }
    }
}
