<?php

namespace Intranet\Modules\Elternsprechtag\Http\Controllers;

use App\Mail\Vorlagen\VorlagenMailer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Intranet\Modules\Elternsprechtag\ElternsprechtagServiceProvider;
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Support\InfoMail;
use Intranet\Modules\Elternsprechtag\Support\Klassen;
use Intranet\Modules\Elternsprechtag\Support\Namen;

/** Verwaltung: Sprechtage anlegen, Eltern informieren, Stand der Lehrer. */
class SprechtagController
{
    public function index(): View
    {
        $alle = Sprechtag::with('tage')->withCount('plaene')->get()
            ->sortByDesc(fn (Sprechtag $s) => $s->tage->first()?->datum)
            ->values();

        return view('elternsprechtag::sprechtage.index', [
            'kommend' => $alle->reject->vorbei()->values(),
            'vergangen' => $alle->filter->vorbei()->values(),
        ]);
    }

    public function create(): View
    {
        return $this->formular(new Sprechtag(['klassen' => []]));
    }

    public function edit(Sprechtag $sprechtag): View
    {
        return $this->formular($sprechtag->load('tage'));
    }

    public function store(Request $request): RedirectResponse
    {
        $sprechtag = DB::transaction(function () use ($request) {
            $sprechtag = new Sprechtag(['angelegt_von' => $request->user()->id]);

            return $this->uebernehmen($request, $sprechtag);
        });

        $this->audit('elternsprechtag.angelegt', $sprechtag);

        return redirect()->route('module.elternsprechtag.sprechtage.show', $sprechtag)
            ->with('status', 'Elternsprechtag angelegt.');
    }

    public function update(Request $request, Sprechtag $sprechtag): RedirectResponse
    {
        DB::transaction(fn () => $this->uebernehmen($request, $sprechtag));

        $this->audit('elternsprechtag.geaendert', $sprechtag);

        return redirect()->route('module.elternsprechtag.sprechtage.show', $sprechtag)
            ->with('status', 'Elternsprechtag gespeichert.');
    }

    public function destroy(Sprechtag $sprechtag): RedirectResponse
    {
        $this->audit('elternsprechtag.geloescht', $sprechtag);
        $sprechtag->delete();

        return redirect()->route('module.elternsprechtag.sprechtage.index')
            ->with('status', 'Elternsprechtag gelöscht.');
    }

    /** Übersicht: Tage, Klassen, Eltern-Info und Stand jedes Lehrers. */
    public function show(Sprechtag $sprechtag): View
    {
        $sprechtag->load('tage');
        $plaene = $this->plaene($sprechtag);

        return view('elternsprechtag::sprechtage.show', [
            'sprechtag' => $sprechtag,
            'lehrer' => $this->lehrer($sprechtag, $plaene),
            'plaene' => $plaene,
            'eltern' => Klassen::eltern($sprechtag->klassenListe()),
            'einladungen' => InfoMail::einladungen($sprechtag),
        ]);
    }

    /** Info an alle Eltern der beteiligten Klassen – über den Ausgangskorb. */
    public function info(Request $request, Sprechtag $sprechtag, VorlagenMailer $mailer): RedirectResponse
    {
        $sprechtag->load('tage');

        if ($sprechtag->tage->isEmpty() || $sprechtag->klassenListe() === []) {
            return back()->with('error', 'Erst Tage und Klassen eintragen.');
        }

        // Klassenlehrer der beteiligten Klassen müssen vor dem ersten Veröffentlichen
        // freigegeben oder abgesagt haben; alle anderen dürfen fehlen.
        $fehlend = $this->lehrer($sprechtag, $this->plaene($sprechtag))->filter(fn (array $z) => $z['pflichtFehlt']);
        if (! $sprechtag->info_versendet_am && $fehlend->isNotEmpty()) {
            return back()->with('error', 'Noch nicht möglich: Diese Klassenlehrkräfte haben ihren Plan weder freigegeben noch abgesagt – '
                .$fehlend->map(fn (array $z) => Namen::anzeige($z['user']->name))->join(' · ').'.');
        }

        // Erneut senden: an alle sofort. Erstes Veröffentlichen: zuerst die persönlich
        // eingeladenen Eltern, die übrigen nach dem Vorsprung (Command info-nachsenden).
        $erneut = $sprechtag->info_versendet_am !== null;
        $vorsprung = $erneut ? 0 : (int) ($request->validate(['vorsprung_minuten' => ['nullable', 'integer', 'min:0', 'max:1440']])['vorsprung_minuten'] ?? 60);
        $alleJetzt = $erneut || $vorsprung === 0;

        [$gesendet, $fehler] = InfoMail::versenden($sprechtag, $mailer, $alleJetzt ? InfoMail::empfaenger($sprechtag) : InfoMail::eingeladene($sprechtag));

        $sprechtag->update($erneut ? ['info_empfaenger' => $gesendet, 'info_rest_versendet_am' => $sprechtag->info_rest_versendet_am ?? now()] : [
            'info_versendet_am' => now(),
            'info_empfaenger' => $gesendet,
            'vorsprung_minuten' => $vorsprung,
            'info_rest_versendet_am' => $alleJetzt ? now() : null,
        ]);
        $this->audit('elternsprechtag.info', $sprechtag, ['empfaenger' => $gesendet, 'fehler' => $fehler, 'vorsprung' => $alleJetzt ? 0 : $vorsprung]);

        $rest = $alleJetzt ? 0 : InfoMail::uebrige($sprechtag)->count();

        return back()->with('status', ($erneut ? 'Info erneut' : 'Termine veröffentlicht. Info').' an '.$gesendet.($alleJetzt ? '' : ' persönlich eingeladene').' Eltern in den Ausgangskorb gelegt.'
            .($rest > 0 ? ' Die übrigen '.$rest.' Eltern folgen um '.$sprechtag->restFaelligAm()->format('H:i').' Uhr.' : '')
            .($fehler > 0 ? " {$fehler} fehlgeschlagen (siehe Log)." : ''));
    }

    /** Vorschau der Info-Mail, wie sie ein bestimmtes Elternteil bekäme (JSON für die Übersicht). */
    public function vorschau(Request $request, Sprechtag $sprechtag, VorlagenMailer $mailer): JsonResponse
    {
        $sprechtag->load('tage');
        $eltern = User::findOrFail((int) $request->query('eltern'));
        abort_unless(Klassen::eltern($sprechtag->klassenListe())->contains('id', $eltern->id), 404);

        [$html, $text] = InfoMail::werte($sprechtag, $eltern, InfoMail::einladungen($sprechtag)->get($eltern->id, []));
        $mail = $mailer->rendern(ElternsprechtagServiceProvider::VORLAGE_INFO, $html, $text);

        return response()->json([
            'an' => $eltern->email ?: '(keine Mailadresse)',
            'betreff' => $mail['betreff'],
            'html' => $mail['html'],
            'gesperrt' => $eltern->istGesperrt(),
        ]);
    }

    /** @return Collection<int, Plan>  Schlüssel = Lehrer-ID */
    private function plaene(Sprechtag $sprechtag): Collection
    {
        return Plan::with(['tage', 'wuensche', 'termine', 'user.roles'])
            ->where('sprechtag_id', $sprechtag->id)
            ->get()
            ->keyBy('user_id');
    }

    /**
     * Lehrer laut Linear, Klassenlehrer der Klassen und alle, die selbst einen
     * Plan angelegt haben. `klassen` ist die eigene Wahl (sonst die Linear-
     * Vorauswahl) ohne die Klassen, für die er Klassenlehrer ist – die stehen in
     * `klassenlehrer`. Für Klassenlehrer ist eine Angabe Pflicht: freigegeben
     * oder abgesagt.
     *
     * @param  Collection<int, Plan>  $plaene
     * @return Collection<int, array{user: User, klassen: list<string>, klassenlehrer: list<string>, angabe: bool, pflichtFehlt: bool}>
     */
    private function lehrer(Sprechtag $sprechtag, Collection $plaene): Collection
    {
        $klassen = $sprechtag->klassenListe();

        $liste = Klassen::lehrer($klassen)->pluck('user')
            ->concat(Klassen::klassenlehrer($klassen))
            ->concat($plaene->pluck('user')->filter())
            ->unique('id')
            ->sortBy(fn (User $u) => Namen::schluessel($u->name))
            ->map(function (User $u) use ($plaene, $sprechtag, $klassen) {
                $plan = $plaene->get($u->id);
                $klassenlehrer = Klassen::alsKlassenlehrer($u, $klassen);

                return [
                    'user' => $u,
                    'klassen' => array_values(array_diff(Plan::klassenVon($plan, $u, $sprechtag), $klassenlehrer)),
                    'klassenlehrer' => $klassenlehrer,
                    'angabe' => $plan !== null && ($plan->freigegeben() || $plan->abgesagt()),
                    'freigegeben' => $plan !== null && $plan->freigegeben(),
                ];
            })
            ->values();

        // Eine Klasse ist versorgt, sobald eine ihrer Klassenlehrkräfte den Plan freigegeben hat –
        // die übrigen Klassenlehrkräfte dieser Klasse halten das Veröffentlichen dann nicht mehr auf.
        $versorgt = $liste->where('freigegeben', true)->flatMap(fn (array $z) => $z['klassenlehrer'])->unique()->all();

        return $liste->map(fn (array $z) => $z + [
            'pflichtFehlt' => ! $z['angabe'] && array_diff($z['klassenlehrer'], $versorgt) !== [],
        ]);
    }

    private function formular(Sprechtag $sprechtag): View
    {
        $alle = Klassen::alle();
        // Klassen, die es im Abgleich nicht mehr gibt, trotzdem anzeigen.
        $alle = array_values(array_unique(array_merge($alle, $sprechtag->klassenListe())));
        natcasesort($alle);

        return view('elternsprechtag::sprechtage.form', [
            'sprechtag' => $sprechtag,
            'alleKlassen' => array_values($alle),
            'tage' => $sprechtag->exists
                ? $sprechtag->tage->map(fn ($t) => ['id' => $t->id, 'datum' => $t->datum->toDateString(), 'von' => $t->standard_von, 'bis' => $t->standard_bis])->values()
                : collect([['id' => null, 'datum' => '', 'von' => '08:00', 'bis' => '15:00']]),
        ]);
    }

    private function uebernehmen(Request $request, Sprechtag $sprechtag): Sprechtag
    {
        $daten = $request->validate([
            'titel' => ['required', 'string', 'max:255'],
            'klassen' => ['array'],
            'klassen.*' => ['string', 'max:100'],
            'lehrer_frist' => ['nullable', 'date'],
            'hinweis' => ['nullable', 'string', 'max:5000'],
            'tage' => ['required', 'array', 'min:1'],
            'tage.*.id' => ['nullable', 'integer'],
            'tage.*.datum' => ['required', 'date'],
            'tage.*.von' => ['nullable', 'date_format:H:i'],
            'tage.*.bis' => ['nullable', 'date_format:H:i', 'after:tage.*.von'],
        ], [
            'tage.required' => 'Mindestens einen Tag eintragen.',
            'tage.*.datum.required' => 'Jeder Tag braucht ein Datum.',
            'tage.*.bis.after' => 'Das Ende muss nach dem Beginn liegen.',
        ]);

        $sprechtag->fill([
            'titel' => $daten['titel'],
            'klassen' => array_values(array_unique($daten['klassen'] ?? [])),
            'lehrer_frist' => $daten['lehrer_frist'] ?? null,
            'hinweis' => $daten['hinweis'] ?? null,
        ])->save();

        // Bestehende Tage behalten (an ihnen hängen die Pläne der Lehrer),
        // weggelassene löschen, neue anlegen.
        $behalten = [];
        foreach ($daten['tage'] as $t) {
            $tag = isset($t['id']) ? $sprechtag->tage()->find($t['id']) : null;
            $tag ??= $sprechtag->tage()->make();
            $tag->fill(['datum' => $t['datum'], 'standard_von' => $t['von'] ?? null, 'standard_bis' => $t['bis'] ?? null])->save();
            $behalten[] = $tag->id;
        }
        $sprechtag->tage()->whereNotIn('id', $behalten)->delete();

        return $sprechtag;
    }

    private function audit(string $aktion, Sprechtag $sprechtag, array $daten = []): void
    {
        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben($aktion, $sprechtag->titel, daten: $daten + ['sprechtag' => $sprechtag->id], ziel: "Elternsprechtag {$sprechtag->id}");
        }
    }
}
