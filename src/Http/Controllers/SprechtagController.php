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
        $eltern = Klassen::eltern($sprechtag->klassenListe());

        return view('elternsprechtag::sprechtage.show', [
            'sprechtag' => $sprechtag,
            'lehrer' => $this->lehrer($sprechtag, $plaene),
            'plaene' => $plaene,
            'eltern' => $eltern,
            'elternStand' => $sprechtag->fuerAlleOffen() ? $this->elternStand($sprechtag, $eltern) : null,
            'einladungen' => InfoMail::einladungen($sprechtag),
            'wunschgespraeche' => InfoMail::wunschgespraeche($sprechtag),
            'besuche' => DB::table('elternsprechtag_besuche')->where('sprechtag_id', $sprechtag->id)->whereNotNull('letzter_besuch_am')->pluck('letzter_besuch_am', 'user_id'),
            'keineZeit' => DB::table('elternsprechtag_besuche')->where('sprechtag_id', $sprechtag->id)->whereNotNull('keine_zeit_am')->get()->keyBy('user_id'),
            // Wer selbst gebucht hat, war auf der Seite – auch vor der Besuchszählung.
            'selbstGebucht' => DB::table('elternsprechtag_termine')
                ->join('elternsprechtag_plaene', 'elternsprechtag_plaene.id', '=', 'elternsprechtag_termine.plan_id')
                ->where('elternsprechtag_plaene.sprechtag_id', $sprechtag->id)
                ->whereColumn('elternsprechtag_termine.gebucht_von', 'elternsprechtag_termine.eltern_user_id')
                ->distinct()->pluck('elternsprechtag_termine.gebucht_von')->flip(),
            'zustellung' => $sprechtag->veroeffentlicht()
                ? InfoMail::zustellung($sprechtag, $sprechtag->fuerAlleOffen() ? 'Elternsprechtag-Info' : 'Elternsprechtag-Einladung')
                : null,
        ]);
    }

    /**
     * Stand der normalen Einladung: je Elternteil (nicht gesperrt) die Kinder
     * mit Klasse und die Zahl gebuchter Gespräche – ohne Termin zuerst.
     */
    private function elternStand(Sprechtag $sprechtag, Collection $eltern): Collection
    {
        $termine = \Intranet\Modules\Elternsprechtag\Models\Termin::whereHas('plan', fn ($q) => $q->where('sprechtag_id', $sprechtag->id))
            ->get(['id', 'kind_user_id', 'plan_id', 'gruppe']);
        // Ein gemeinsames Gespräch mehrerer Klassenlehrer zählt einmal.
        $jeKind = $termine->groupBy('kind_user_id')->map(fn ($ts) => $ts->unique(fn ($t) => $t->gruppe ?: 'p'.$t->plan_id)->count());
        $klassen = $sprechtag->klassenListe();

        return User::with('children.roles')->whereIn('id', $eltern->pluck('id'))->whereNull('gesperrt_am')->get()
            ->map(function (User $e) use ($jeKind, $klassen) {
                $kinder = $e->children
                    ->map(fn (User $k) => ['kind' => $k, 'klassen' => array_values(array_intersect(Klassen::vonKind($k), $klassen))])
                    ->filter(fn ($z) => $z['klassen'] !== []);

                return [
                    'eltern' => $e,
                    'kinder' => $kinder->map(fn ($z) => Namen::anzeige($z['kind']->name).' ('.implode(', ', $z['klassen']).')')->join(', '),
                    'termine' => $kinder->sum(fn ($z) => $jeKind[$z['kind']->id] ?? 0),
                ];
            })
            ->sortBy(fn ($z) => [$z['termine'] > 0 ? 1 : 0, Namen::schluessel($z['eltern']->name)])
            ->values();
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

        // Zwei Stufen: erst die Wunschgespräche (Mail nur an persönlich eingeladene
        // Eltern, sie buchen nur dort), dann alle Gespräche (Mail an alle Eltern).
        // Ohne Gesprächswünsche oder auf Wunsch geht beides auf einmal.
        $stufe = $request->validate(['stufe' => ['nullable', 'in:wunsch,alle']])['stufe'] ?? 'alle';
        $erneut = $sprechtag->fuerAlleOffen();
        $wunschStufe = ! $sprechtag->veroeffentlicht() && $stufe === 'wunsch' && InfoMail::einladungen($sprechtag)->isNotEmpty();
        $empfaenger = $wunschStufe ? InfoMail::eingeladene($sprechtag) : InfoMail::empfaenger($sprechtag);

        [$gesendet, $fehler] = InfoMail::versenden($sprechtag, $mailer, $empfaenger,
            $wunschStufe ? ElternsprechtagServiceProvider::VORLAGE_WUNSCH : ElternsprechtagServiceProvider::VORLAGE_INFO);

        $sprechtag->update([
            'info_versendet_am' => $sprechtag->info_versendet_am ?? now(),
            'info_empfaenger' => $gesendet,
            'info_rest_versendet_am' => $wunschStufe ? null : ($sprechtag->info_rest_versendet_am ?? now()),
        ]);
        $this->audit('elternsprechtag.info', $sprechtag, ['empfaenger' => $gesendet, 'fehler' => $fehler, 'stufe' => $wunschStufe ? 'wunsch' : ($erneut ? 'erneut' : 'alle')]);

        $text = match (true) {
            $wunschStufe => 'Wunschgespräche veröffentlicht. Info an '.$gesendet.' persönlich eingeladene Eltern in den Ausgangskorb gelegt – sie können jetzt nur diese Gespräche buchen.',
            $erneut => 'Info erneut an '.$gesendet.' Eltern in den Ausgangskorb gelegt.',
            default => 'Alle Gespräche veröffentlicht. Info an '.$gesendet.' Eltern in den Ausgangskorb gelegt.',
        };

        return back()->with('status', $text.($fehler > 0 ? " {$fehler} fehlgeschlagen (siehe Log)." : ''));
    }

    /**
     * Vorschau einer Mail, wie sie ein bestimmtes Elternteil bekäme (JSON für die
     * Übersicht). `art=wunsch` = Einladung der ersten Stufe, sonst die Info an alle.
     */
    public function vorschau(Request $request, Sprechtag $sprechtag, VorlagenMailer $mailer): JsonResponse
    {
        $sprechtag->load('tage');
        $eltern = User::findOrFail((int) $request->query('eltern'));
        abort_unless(Klassen::eltern($sprechtag->klassenListe())->contains('id', $eltern->id), 404);

        $einladung = InfoMail::einladungen($sprechtag)->get($eltern->id, []);
        $wunsch = $request->query('art') === 'wunsch';
        if ($wunsch && $einladung === []) {
            return response()->json(['an' => $eltern->email ?: '(keine Mailadresse)', 'betreff' => '–', 'gesperrt' => $eltern->istGesperrt(),
                'html' => '<p style="font-family:sans-serif;color:#6b7280;padding:16px;">Dieses Elternteil ist nicht persönlich eingeladen und bekommt in der ersten Stufe keine Mail.</p>']);
        }

        [$html, $text] = InfoMail::werte($sprechtag, $eltern, $einladung);
        $mail = $mailer->rendern($wunsch ? ElternsprechtagServiceProvider::VORLAGE_WUNSCH : ElternsprechtagServiceProvider::VORLAGE_INFO, $html, $text);

        $anhaenge = array_map(fn ($a) => [
            'name' => $a['name'],
            'groesse' => number_format(filesize($a['pfad']) / 1024, 0, ',', '.').' KB',
            'url' => route('module.elternsprechtag.sprechtage.elterninfo'),
        ], InfoMail::elternPdf());

        return response()->json([
            'an' => $eltern->email ?: '(keine Mailadresse)',
            'betreff' => $mail['betreff'],
            'html' => $mail['html'],
            'gesperrt' => $eltern->istGesperrt(),
            'anhaenge' => $anhaenge,
        ]);
    }

    /** Die Elternanleitung, die an beiden Info-Mails hängt – zum Ansehen aus der Vorschau. */
    public function elternPdf(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $anhang = InfoMail::elternPdf()[0] ?? abort(404);

        return response()->file($anhang['pfad'], ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$anhang['name'].'"']);
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
            'eltern_frist' => ['nullable', 'date'],
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
            'eltern_frist' => $daten['eltern_frist'] ?? null,
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

        // Frist der Eltern nach hinten verschoben? Dann ist die Buchung wieder offen
        // und die Terminlisten gehen beim neuen Schluss erneut raus.
        $sprechtag->load('tage');
        if ($sprechtag->schluss_gemeldet_am !== null && ! $sprechtag->buchungGeschlossen()) {
            $sprechtag->forceFill(['schluss_gemeldet_am' => null])->save();
        }

        return $sprechtag;
    }

    private function audit(string $aktion, Sprechtag $sprechtag, array $daten = []): void
    {
        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben($aktion, $sprechtag->titel, daten: $daten + ['sprechtag' => $sprechtag->id], ziel: "Elternsprechtag {$sprechtag->id}");
        }
    }
}
