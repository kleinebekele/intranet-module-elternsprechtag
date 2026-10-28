<?php

namespace Intranet\Modules\Elternsprechtag\Http\Controllers;

use App\Mail\Vorlagen\VorlagenMailer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Intranet\Modules\Elternsprechtag\ElternsprechtagServiceProvider;
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Support\Klassen;

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
        $klassen = $sprechtag->klassenListe();

        $plaene = Plan::with(['tage', 'wuensche', 'termine', 'user.roles'])
            ->where('sprechtag_id', $sprechtag->id)
            ->get()
            ->keyBy('user_id');

        // Lehrer laut Linear plus alle, die selbst einen Plan angelegt haben;
        // die Klassen je Lehrer sind seine eigene Wahl, sonst die Linear-Vorauswahl.
        $lehrer = Klassen::lehrer($klassen)->pluck('user')
            ->concat($plaene->pluck('user')->filter())
            ->unique('id')
            ->sortBy(fn (User $u) => mb_strtolower($u->name))
            ->map(fn (User $u) => ['user' => $u, 'klassen' => Plan::klassenVon($plaene->get($u->id), $u, $sprechtag)])
            ->values();

        return view('elternsprechtag::sprechtage.show', [
            'sprechtag' => $sprechtag,
            'lehrer' => $lehrer,
            'plaene' => $plaene,
            'eltern' => Klassen::eltern($klassen),
        ]);
    }

    /** Info an alle Eltern der beteiligten Klassen – über den Ausgangskorb. */
    public function info(Request $request, Sprechtag $sprechtag, VorlagenMailer $mailer): RedirectResponse
    {
        $sprechtag->load('tage');

        if ($sprechtag->tage->isEmpty() || $sprechtag->klassenListe() === []) {
            return back()->with('error', 'Erst Tage und Klassen eintragen.');
        }

        $empfaenger = Klassen::eltern($sprechtag->klassenListe())
            ->reject(fn (User $u) => $u->istGesperrt() || blank($u->email));

        $werte = [
            'sprechtag' => $sprechtag->titel,
            'tage' => $sprechtag->tageText(),
            'klassen' => implode(', ', $sprechtag->klassenListe()),
            'link' => route('module.elternsprechtag.eltern.index'),
        ];
        $hinweis = (string) $sprechtag->hinweis;

        $gesendet = 0;
        $fehler = 0;
        foreach ($empfaenger as $u) {
            $text = $werte + ['name' => $u->name, 'hinweis' => $hinweis];
            $html = array_map('e', $text);
            $html['hinweis'] = nl2br(e($hinweis));

            try {
                $mailer->senden(
                    ElternsprechtagServiceProvider::VORLAGE_INFO, $u->email, $html, $text,
                    quelle: 'Elternsprechtag-Info', referenz: 'elternsprechtag:'.$sprechtag->id.':'.$u->id, modul: 'Elternsprechtag',
                );
                $gesendet++;
            } catch (\Throwable $e) {
                $fehler++;
                Log::warning('Elternsprechtag-Info nicht verschickt.', ['user' => $u->id, 'fehler' => $e->getMessage()]);
            }
        }

        $sprechtag->update(['info_versendet_am' => now(), 'info_empfaenger' => $gesendet]);
        $this->audit('elternsprechtag.info', $sprechtag, ['empfaenger' => $gesendet, 'fehler' => $fehler]);

        return back()->with('status', "Termine veröffentlicht. Info an {$gesendet} Eltern in den Ausgangskorb gelegt."
            .($fehler > 0 ? " {$fehler} fehlgeschlagen (siehe Log)." : ''));
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
