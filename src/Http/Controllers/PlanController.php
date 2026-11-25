<?php

namespace Intranet\Modules\Elternsprechtag\Http\Controllers;

use App\Mail\Vorlagen\VorlagenMailer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Intranet\Modules\Elternsprechtag\ElternsprechtagServiceProvider;
use Intranet\Modules\Elternsprechtag\Models\Klassenteam;
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Models\Termin;
use Intranet\Modules\Elternsprechtag\Support\Ausfall;
use Intranet\Modules\Elternsprechtag\Support\Klassen;
use Intranet\Modules\Elternsprechtag\Support\Namen;
use Intranet\Modules\Elternsprechtag\Support\Rechte;
use Intranet\Modules\Elternsprechtag\Support\Slots;
use Intranet\Modules\Elternsprechtag\Support\Team;

/**
 * Terminplan eines Lehrers: Zeiten je Tag, Pausen, Gesprächslänge,
 * Vorbereitung und die Eltern, mit denen er unbedingt sprechen will.
 */
class PlanController
{
    /** Die kommenden Sprechtage – für jeden Lehrer, welche Klassen er übernimmt, wählt er im Plan. */
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($request->filled('lehrer') && (int) $request->input('lehrer') !== $user->id) {
            abort_unless(Rechte::istVerwaltung($user), 403);
            $user = User::with('roles')->findOrFail((int) $request->input('lehrer'));
        }
        $plaene = Plan::with(['tage', 'termine'])->where('user_id', $user->id)->get()->keyBy('sprechtag_id');
        $lehrer = Rechte::istLehrer($user) || $plaene->isNotEmpty();

        $sprechtage = ! $lehrer ? collect() : Sprechtag::with('tage')->get()
            ->reject->vorbei()
            ->sortBy(fn (Sprechtag $s) => $s->tage->first()?->datum)
            ->values();

        // Direkt in den aktuellen Sprechtag: der veröffentlichte, sonst der einzige
        // anstehende. `?liste=1` (Link „zurück") zeigt die Übersicht.
        if (! $request->boolean('liste')) {
            $aktuell = $sprechtage->first(fn (Sprechtag $s) => $s->veroeffentlicht())
                ?? ($sprechtage->count() === 1 ? $sprechtage->first() : null);
            if ($aktuell && Rechte::istLehrerIn($user, $aktuell)) {
                return redirect()->route('module.elternsprechtag.plan.zeigen', $user->is($request->user())
                    ? [$aktuell] : [$aktuell, 'lehrer' => $user->id]);
            }
        }

        return view('elternsprechtag::plan.index', [
            'sprechtage' => $sprechtage,
            'plaene' => $plaene,
            'lehrer' => $lehrer,
            'person' => $user,
            'fremd' => ! $user->is($request->user()),
            'verwaltung' => Rechte::istVerwaltung($request->user()),
            'personen' => $this->allePersonen($request),
            'klassen' => $sprechtage->mapWithKeys(fn (Sprechtag $s) => [
                $s->id => Plan::klassenVon($plaene->get($s->id), $user, $s),
            ]),
        ]);
    }

    /** Für „Handeln als": alle Lehrer laut Linear plus alle mit einem Plan. */
    private function allePersonen(Request $request)
    {
        if (! Rechte::istVerwaltung($request->user())) {
            return collect();
        }

        return Klassen::alleLehrer()
            ->concat(User::whereIn('id', Plan::query()->select('user_id'))->get())
            ->unique('id')
            ->sortBy(fn (User $u) => Namen::schluessel($u->name))
            ->values();
    }

    public function zeigen(Request $request, Sprechtag $sprechtag): View
    {
        $lehrer = $this->lehrer($request, $sprechtag);
        $sprechtag->load('tage');

        // Einheitliches Raster: Ruft die Lehrkraft die Seite zum ersten Mal selbst auf,
        // gleich Plan und alle Tage anlegen – dann sind die Uhrzeiten sofort markierbar.
        if ($sprechtag->einheitlich() && $lehrer->is($request->user()) && ! $sprechtag->vorbei()
            && ! Plan::where(['sprechtag_id' => $sprechtag->id, 'user_id' => $lehrer->id])->exists()) {
            DB::transaction(function () use ($sprechtag, $lehrer) {
                $neu = Plan::create([
                    'sprechtag_id' => $sprechtag->id,
                    'user_id' => $lehrer->id,
                    'klassen' => Plan::klassenVon(null, $lehrer, $sprechtag),
                    'slot_minuten' => $sprechtag->slot_minuten,
                    'vorbereitung_minuten' => 0,
                ]);
                foreach ($sprechtag->tage as $t) {
                    $neu->tage()->create(['tag_id' => $t->id, 'von' => $t->standard_von ?? '08:00', 'bis' => $t->standard_bis ?? '15:00', 'pausen' => []]);
                }
            });
        }

        $plan = Plan::with(['tage.tag', 'wuensche', 'termine.tag', 'termine.kind', 'termine.eltern'])->firstOrNew(['sprechtag_id' => $sprechtag->id, 'user_id' => $lehrer->id]);
        $klassen = Plan::klassenVon($plan->exists ? $plan : null, $lehrer, $sprechtag);
        $planTage = $plan->tage->keyBy('tag_id');
        $team = $this->teamDaten($plan, $lehrer, $sprechtag);

        // Neuer Plan eines Klassenlehrers: Gesprächsdauer und Vorbereitung wie beim Partner.
        if (! $plan->exists && ($vorlage = $team['partnerPlaene']->first())) {
            $plan->slot_minuten = $vorlage->slot_minuten;
            $plan->vorbereitung_minuten = $vorlage->vorbereitung_minuten;
        }

        return view('elternsprechtag::plan.zeigen', [
            'sprechtag' => $sprechtag,
            'lehrer' => $lehrer,
            'fremd' => ! $lehrer->is($request->user()),
            'plan' => $plan,
            'klassen' => $klassen,
            'linearKlassen' => Plan::klassenVon(null, $lehrer, $sprechtag),
            'planTage' => $planTage,
            // Alle Klassen des Sprechtags; die Seite blendet die nicht gewählten aus.
            'kinder' => Klassen::kinder($sprechtag->klassenListe()),
            'wuensche' => $plan->wuensche->keyBy('kind_user_id'),
            'darfAendern' => Rechte::darfPlanAendern($request->user(), $sprechtag, $lehrer),
            'klasse' => $this->klassenTermine($lehrer, $sprechtag),
            'darfAbsagen' => ! $plan->abgesagt() && Rechte::darfAbsagen($request->user(), $sprechtag, $lehrer),
            // Raster markieren: wie Absagen bis zum Ende des Sprechtags.
            'darfMarkieren' => ! $plan->abgesagt() && Rechte::darfAbsagen($request->user(), $sprechtag, $lehrer),
            // Mailvorschau fürs Absage-Fenster (erstes betroffenes Elternteil).
            'ausfall' => $plan->abgesagt() ? null : Ausfall::vorschau($sprechtag, $lehrer->name, $plan->termine),
            'verwaltung' => Rechte::istVerwaltung($request->user()),
            'personen' => $this->allePersonen($request),
            'team' => $team,
        ]);
    }

    /**
     * Für Klassenlehrkräfte: je Kind der eigenen Klassen die gebuchten Termine
     * (Zeit, Lehrkräfte, Raum) – damit sie sehen, wo sie dazukommen können.
     * null, wenn die Lehrkraft in keiner Klasse des Sprechtags Klassenlehrkraft ist.
     *
     * @return array{klassen: list<string>, zeilen: \Illuminate\Support\Collection}|null
     */
    private function klassenTermine(User $lehrer, Sprechtag $sprechtag): ?array
    {
        $klassen = Klassen::alsKlassenlehrer($lehrer, $sprechtag->klassenListe());
        if ($klassen === []) {
            return null;
        }

        $kinder = Klassen::kinder($klassen);
        $termine = Termin::with(['plan.user', 'tag', 'dazu'])
            ->whereIn('kind_user_id', $kinder->map(fn ($z) => $z['kind']->id))
            ->whereHas('plan', fn ($q) => $q->where('sprechtag_id', $sprechtag->id))
            ->get()
            ->groupBy('kind_user_id');

        $zeilen = $kinder->map(function ($z) use ($termine, $lehrer) {
            // Gemeinsame Gespräche stehen in mehreren Plänen – einmal zeigen, mit allen Namen.
            $liste = ($termine->get($z['kind']->id) ?? collect())
                ->groupBy(fn (Termin $t) => $t->gruppe ?: 't'.$t->id)
                ->map(fn ($gruppe) => [
                    'sort' => $gruppe->first()->tag?->datum?->format('Y-m-d').' '.$gruppe->first()->von,
                    'zeit' => $gruppe->first()->tag?->beschriftung().', '.$gruppe->first()->von.'–'.$gruppe->first()->bis.' Uhr',
                    'namen' => $gruppe->map(fn (Termin $t) => Namen::anzeige($t->plan?->user?->name))->filter()->join(' & '),
                    'raum' => $gruppe->first()->telefon ? 'Telefon' : ($gruppe->first()->raum ?: $gruppe->map(fn (Termin $t) => $t->plan?->raum)->filter()->unique()->join(', ')),
                    'eigen' => $gruppe->contains(fn (Termin $t) => (int) $t->plan?->user_id === $lehrer->id),
                    'termin' => $gruppe->first(),
                    'dazu' => $gruppe->first()->dazu->contains('id', $lehrer->id),
                    'vorbei' => $gruppe->first()->beginn()->isPast(),
                ])
                ->sortBy('sort')->values();

            return $z + ['termine' => $liste];
        });

        return ['klassen' => $klassen, 'zeilen' => $zeilen];
    }

    /**
     * Klassenlehrer-Team des Lehrers: Partner je Klasse, deren Pläne, Modus,
     * Anzahl gemeinsamer Uhrzeiten, Partner-Namen je Termingruppe und die
     * Termine, die nur bei einem Partner gebucht werden konnten.
     *
     * @return array{klassen: array, partnerPlaene: \Illuminate\Support\Collection, partnerVon: \Illuminate\Support\Collection, beiPartner: \Illuminate\Support\Collection}
     */
    private function teamDaten(Plan $plan, User $lehrer, Sprechtag $sprechtag): array
    {
        $partner = Team::partner($lehrer, $sprechtag);
        $ids = collect($partner)->flatten()->pluck('id')->unique()->all();
        $partnerPlaene = Plan::with(['tage.tag', 'termine', 'user'])
            ->where('sprechtag_id', $sprechtag->id)->whereIn('user_id', $ids)->get();
        $modi = Team::modi($sprechtag);

        $klassen = [];
        foreach ($partner as $klasse => $users) {
            $plaene = $partnerPlaene->whereIn('user_id', $users->pluck('id'))->reject->abgesagt()->values();
            $klassen[$klasse] = [
                'partner' => $users,
                'modus' => $modi[$klasse] ?? 'wunsch',
                'raumVon' => Klassenteam::where(['sprechtag_id' => $sprechtag->id, 'klasse' => $klasse])->value('raum_user_id'),
                // Wählbare Räume: eigener und die der Partner (aus ihren Plänen).
                'raeume' => collect([$lehrer->id => $plan->raum])
                    ->union($partnerPlaene->whereIn('user_id', $users->pluck('id'))->mapWithKeys(fn (Plan $p) => [$p->user_id => $p->raum])),
                'ohnePlan' => $users->reject(fn (User $u) => $partnerPlaene->contains('user_id', $u->id)),
                'gemeinsam' => $plan->exists && $plaene->isNotEmpty() ? count(Team::slots(collect([$plan])->concat($plaene), collect())) : null,
            ];
        }

        $gruppen = $plan->termine->pluck('gruppe')->filter()->unique();
        $partnerVon = $gruppen->isEmpty() ? collect() : Termin::with('plan.user')->whereIn('gruppe', $gruppen)->where('plan_id', '!=', $plan->id ?? 0)->get()
            ->groupBy('gruppe')->map(fn ($ts) => $ts->map(fn ($t) => Namen::anzeige($t->plan?->user?->name))->join(' & '));

        return [
            'klassen' => $klassen,
            'partnerPlaene' => $partnerPlaene,
            'partnerVon' => $partnerVon,
            'beiPartner' => Termin::with(['tag', 'kind', 'eltern', 'plan.user'])->where('allein', true)
                ->whereIn('plan_id', $partnerPlaene->pluck('id'))->get()
                ->sortBy(fn ($t) => $t->tag?->datum?->format('Y-m-d').' '.$t->von)->values(),
        ];
    }

    /** Terminliste zum Ausdrucken: je Tag alle Slots mit Kind und Eltern, dazu offene Gesprächswünsche. */
    public function pdf(Request $request, Sprechtag $sprechtag)
    {
        $sprechtag->loadMissing('tage');
        if (! $sprechtag->pdfFrei($request->user())) {
            return back()->with('error', 'Die Terminliste als PDF gibt es nach dem Buchungsschluss – dann ist sie endgültig. Bis dahin zeigt das Auge den aktuellen Stand.');
        }
        $daten = $this->listenDaten($request, $sprechtag);

        return \Barryvdh\DomPDF\Facade\Pdf::setOption('isFontSubsettingEnabled', true)
            ->loadView('elternsprechtag::plan.pdf', $daten)
            ->download('elternsprechtag-'.str($daten['lehrer']->name)->slug().'.pdf');
    }

    /** Dieselbe Terminliste als Seite – für die Vorschau (Auge) im Terminplan. */
    public function ansicht(Request $request, Sprechtag $sprechtag)
    {
        $daten = $this->listenDaten($request, $sprechtag);

        return response()->view('elternsprechtag::plan.pdf', $daten + [
            'vorschau' => true,
            // Für Notiz und Absagen aus der Vorschau: ggf. ?lehrer= mitgeben.
            'param' => $daten['lehrer']->is($request->user()) ? [] : ['lehrer' => $daten['lehrer']->id],
            'verwaltung' => Rechte::istVerwaltung($request->user()),
        ]);
    }

    /** Notiz der Lehrkraft zu einem gebuchten Termin (aus der Vorschau, speichert beim Tippen). */
    public function notiz(Request $request, Termin $termin)
    {
        $termin->load('plan.sprechtag');
        $lehrer = $this->lehrer($request, $termin->plan->sprechtag);
        abort_unless((int) $termin->plan->user_id === $lehrer->id, 404);

        $daten = $request->validate(['notiz' => ['nullable', 'string', 'max:2000']]);
        $termin->update(['notiz' => trim((string) ($daten['notiz'] ?? '')) ?: null]);

        return response()->json(['ok' => true]);
    }

    /** Inhalt der Terminliste (PDF und Vorschau). */
    private function listenDaten(Request $request, Sprechtag $sprechtag): array
    {
        return $this->terminliste($this->lehrer($request, $sprechtag), $sprechtag);
    }

    /** Inhalt der Terminliste eines Lehrers – auch für die Mail mit PDF (Support\Lehrerinfo). */
    public function terminliste(User $lehrer, Sprechtag $sprechtag): array
    {
        $plan = Plan::with(['tage.tag', 'sprechtag', 'termine.kind.roles', 'termine.eltern', 'termine.dazu', 'wuensche.kind.roles'])
            ->where(['sprechtag_id' => $sprechtag->id, 'user_id' => $lehrer->id])->firstOrFail();

        $tage = $plan->tage->filter(fn ($pt) => $pt->tag !== null)->sortBy(fn ($pt) => $pt->tag->datum)->map(function ($pt) use ($plan) {
            $termine = $plan->termine->where('tag_id', $pt->tag_id)->keyBy('von');
            $raster = collect(Slots::raster($plan, $pt))->reject(fn ($s) => $s['status'] === 'aus' && ! $plan->termine->where('tag_id', $pt->tag_id)->contains('von', $s['von']));
            $zeilen = $raster->map(fn ($s) => $s + [
                'termin' => $termine->get($s['von']),
                // Markierte, nicht gebuchte Zeiten erscheinen wie eine Pause, mit ihrer Bezeichnung.
                'pause' => ! in_array($s['status'], Slots::BUCHBAR, true) && ! $termine->has($s['von']),
                'label' => $s['hinweis'] ?? (Slots::STATUS[$s['status']] ?? 'Pause'),
            ])
                ->concat($plan->sprechtag?->einheitlich() ? collect() : collect($pt->pausenListe())->map(fn ($p) => ['von' => $p['von'], 'bis' => $p['bis'], 'termin' => null, 'pause' => true, 'label' => 'Pause']))
                // Termine, die nicht mehr zu den Zeiten passen, trotzdem aufführen.
                ->concat($termine->reject(fn ($t, $von) => $raster->contains('von', $von))
                    ->map(fn ($t) => ['von' => $t->von, 'bis' => $t->bis, 'termin' => $t, 'pause' => false]))
                ->sortBy('von')->values();

            return ['tag' => $pt->tag, 'von' => $pt->von, 'bis' => $pt->bis, 'zeilen' => $zeilen];
        })->values();

        $gebucht = $plan->termine->pluck('kind_user_id')->all();
        $offeneWuensche = $plan->wuensche->filter(fn ($w) => $w->kind && ! in_array($w->kind_user_id, $gebucht))
            ->sortBy(fn ($w) => Namen::schluessel($w->kind->name))->values();

        $team = $this->teamDaten($plan, $lehrer, $sprechtag);

        return [
            'sprechtag' => $sprechtag,
            'lehrer' => $lehrer,
            'plan' => $plan,
            'tage' => $tage,
            'wuensche' => $plan->wuensche->pluck('kind_user_id')->all(),
            'offeneWuensche' => $offeneWuensche,
            'partnerVon' => $team['partnerVon'],
            // Gemeinsame Gespräche: Räume der beteiligten Lehrkräfte (zum Umschalten).
            'raeume' => ($gr = $plan->termine->pluck('gruppe')->filter()->unique())->isEmpty() ? collect()
                : Termin::with('plan')->whereIn('gruppe', $gr)->get()->groupBy('gruppe')
                    ->map(fn ($ts) => $ts->map(fn (Termin $t) => $t->plan?->raum)->filter()->unique()->values()->all()),
            'beiPartner' => $team['beiPartner'],
        ];
    }

    public function speichern(Request $request, Sprechtag $sprechtag): RedirectResponse
    {
        $lehrer = $this->lehrer($request, $sprechtag);
        abort_unless(Rechte::darfPlanAendern($request->user(), $sprechtag, $lehrer), 403, 'Der Terminplan kann nicht mehr geändert werden.');

        // Einheitliches Raster: Dauer legt das Sekretariat fest. Beginn und Ende darf die
        // Lehrkraft im Rahmen des Tages wählen – solange in ihrem Plan noch nichts gebucht ist.
        if ($sprechtag->einheitlich()) {
            $bisher = Plan::with(['tage', 'termine'])->where(['sprechtag_id' => $sprechtag->id, 'user_id' => $lehrer->id])->first();
            $fest = $bisher !== null && $bisher->termine->isNotEmpty();
            $tage = (array) $request->input('tage', []);
            foreach ($sprechtag->tage as $t) {
                $alt = $bisher?->tage->firstWhere('tag_id', $t->id);
                $tage[$t->id] = (array) ($tage[$t->id] ?? []);
                $rahmen = [Slots::minuten($t->standard_von) ?? 0, Slots::minuten($t->standard_bis) ?? 24 * 60];
                $von = $fest ? ($alt->von ?? $t->standard_von) : ($tage[$t->id]['von'] ?? $t->standard_von);
                $bis = $fest ? ($alt->bis ?? $t->standard_bis) : ($tage[$t->id]['bis'] ?? $t->standard_bis);
                // Auf den Tag begrenzen.
                $tage[$t->id]['von'] = Slots::uhrzeit(max($rahmen[0], min($rahmen[1], Slots::minuten($von) ?? $rahmen[0])));
                $tage[$t->id]['bis'] = Slots::uhrzeit(max($rahmen[0], min($rahmen[1], Slots::minuten($bis) ?? $rahmen[1])));
            }
            $request->merge(['slot_minuten' => $sprechtag->slot_minuten, 'vorbereitung_minuten' => 0, 'tage' => $tage]);
        }

        $daten = $request->validate([
            'slot_minuten' => ['required', 'integer', 'min:5', 'max:120'],
            'vorbereitung_minuten' => ['required', 'integer', 'min:0', 'max:60'],
            'raum' => ['nullable', 'string', 'max:255'],
            'bemerkung' => ['nullable', 'string', 'max:2000'],
            'klassen' => ['array'],
            'klassen.*' => ['string', Rule::in($sprechtag->klassenListe())],
            'tage' => ['array'],
            'tage.*.dabei' => ['nullable', 'boolean'],
            'tage.*.von' => ['nullable', 'date_format:H:i'],
            'tage.*.bis' => ['nullable', 'date_format:H:i'],
            'tage.*.pausen' => ['array'],
            'tage.*.pausen.*.von' => ['nullable', 'date_format:H:i'],
            'tage.*.pausen.*.bis' => ['nullable', 'date_format:H:i'],
            'wuensche' => ['array'],
            'wuensche.*' => ['integer'],
            'notiz' => ['array'],
            'notiz.*' => ['nullable', 'string', 'max:255'],
            'team' => ['array'],
            'team_raum' => ['array'],
            'team_raum.*' => ['nullable', 'integer'],
            'team.*' => [Rule::in(array_keys(Team::MODI))],
        ], [
            '*.date_format' => 'Uhrzeiten bitte als HH:MM.',
        ]);

        $tagIds = $sprechtag->tage()->pluck('id')->all();
        $fehler = [];
        foreach ($daten['tage'] ?? [] as $tagId => $t) {
            if (! empty($t['dabei']) && (Slots::minuten($t['bis'] ?? null) ?? 0) <= (Slots::minuten($t['von'] ?? null) ?? PHP_INT_MAX)) {
                $fehler["tage.$tagId.bis"] = 'Ende muss nach dem Beginn liegen.';
            }
        }
        if ($fehler !== []) {
            return back()->withErrors($fehler)->withInput();
        }

        // Klassenlehrer-Team: Gesprächsdauer und Vorbereitung gelten für alle Partner.
        $partner = Team::partner($lehrer, $sprechtag);
        $partnerPlaene = Plan::with(['termine', 'user'])->where('sprechtag_id', $sprechtag->id)
            ->whereIn('user_id', collect($partner)->flatten()->pluck('id')->unique())->get();
        $anders = $partnerPlaene->filter(fn (Plan $p) => (int) $p->slot_minuten !== (int) $daten['slot_minuten']
            || (int) $p->vorbereitung_minuten !== (int) $daten['vorbereitung_minuten']);
        if (! $sprechtag->einheitlich() && $gebucht = $anders->first(fn (Plan $p) => $p->termine->isNotEmpty())) {
            return back()->withInput()->withErrors(['slot_minuten' => 'Gesprächsdauer und Vorbereitungszeit gelten auch für '
                .Namen::anzeige($gebucht->user?->name).' – dort sind schon Termine gebucht. Bitte die bisherigen Werte lassen ('
                .$gebucht->slot_minuten.' / '.$gebucht->vorbereitung_minuten.' Min.).']);
        }

        $klassen = array_values(array_unique($daten['klassen'] ?? []));

        // Wünsche nur für Kinder aus den gewählten Klassen.
        $erlaubt = Klassen::kinder($klassen)->map(fn ($z) => $z['kind']->id)->all();
        $wuensche = array_values(array_intersect(array_map('intval', $daten['wuensche'] ?? []), $erlaubt));

        DB::transaction(function () use ($sprechtag, $lehrer, $daten, $tagIds, $wuensche, $klassen, $request, $partner, $anders) {
            $plan = Plan::firstOrNew(['sprechtag_id' => $sprechtag->id, 'user_id' => $lehrer->id]);
            $plan->fill([
                'abgeschlossen_am' => $request->boolean('freigeben') ? ($plan->abgeschlossen_am ?? now()) : $plan->abgeschlossen_am,
                'klassen' => $klassen,
                'slot_minuten' => $daten['slot_minuten'],
                'vorbereitung_minuten' => $daten['vorbereitung_minuten'],
                'raum' => $daten['raum'] ?? null,
                'bemerkung' => $daten['bemerkung'] ?? null,
            ])->save();

            foreach ($tagIds as $tagId) {
                $t = $daten['tage'][$tagId] ?? [];
                if (empty($t['dabei'])) {
                    $plan->tage()->where('tag_id', $tagId)->delete();

                    continue;
                }
                $pausen = collect($t['pausen'] ?? [])
                    ->filter(fn ($p) => ! empty($p['von']) && ! empty($p['bis']) && Slots::minuten($p['bis']) > Slots::minuten($p['von']))
                    ->map(fn ($p) => ['von' => $p['von'], 'bis' => $p['bis']])
                    ->sortBy('von')->values()->all();

                $plan->tage()->updateOrCreate(['tag_id' => $tagId], ['von' => $t['von'], 'bis' => $t['bis'], 'pausen' => $sprechtag->einheitlich() ? [] : $pausen]);
            }

            $plan->wuensche()->whereNotIn('kind_user_id', $wuensche)->delete();
            foreach ($wuensche as $kindId) {
                $plan->wuensche()->updateOrCreate(['kind_user_id' => $kindId], ['notiz' => $daten['notiz'][$kindId] ?? null]);
            }

            foreach ($anders as $p) {
                $p->update(['slot_minuten' => $daten['slot_minuten'], 'vorbereitung_minuten' => $daten['vorbereitung_minuten']]);
            }
            foreach (array_intersect_key($daten['team'] ?? [], $partner) as $klasse => $modus) {
                $wer = (int) ($daten['team_raum'][$klasse] ?? 0);
                $erlaubt = collect($partner[$klasse])->pluck('id')->push($lehrer->id)->contains($wer);
                Klassenteam::updateOrCreate(['sprechtag_id' => $sprechtag->id, 'klasse' => (string) $klasse],
                    ['modus' => $modus] + ($erlaubt ? ['raum_user_id' => $wer] : []));
            }
        });

        // Raum der schon gebuchten gemeinsamen Gespräche nachziehen (Raumwahl oder eigener Raum geändert).
        foreach (array_keys($partner) as $klasse) {
            Team::raumAktualisieren($sprechtag, (string) $klasse);
        }

        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben('elternsprechtag.plan', $sprechtag->titel, betroffener: $lehrer,
                daten: ['sprechtag' => $sprechtag->id, 'wuensche' => count($wuensche), 'klassen' => $klassen, 'freigegeben' => $request->boolean('freigeben')],
                ziel: "Elternsprechtag {$sprechtag->id}");
        }

        return redirect()->to($this->zurueck($sprechtag, $lehrer, $request))->with('status', $request->boolean('freigeben')
            ? 'Terminplan gespeichert und freigegeben. Die Verwaltung veröffentlicht die Termine für die Eltern.'
            : 'Terminplan gespeichert.');
    }

    /** Freigeben bzw. Freigabe zurücknehmen – damit die Verwaltung den Stand sieht. */
    public function abschliessen(Request $request, Sprechtag $sprechtag): RedirectResponse
    {
        $lehrer = $this->lehrer($request, $sprechtag);
        abort_unless(Rechte::darfPlanAendern($request->user(), $sprechtag, $lehrer), 403, 'Der Terminplan kann nicht mehr geändert werden.');

        $plan = Plan::where(['sprechtag_id' => $sprechtag->id, 'user_id' => $lehrer->id])->firstOrFail();
        $plan->update(['abgeschlossen_am' => $plan->abgeschlossen_am ? null : now()]);

        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben('elternsprechtag.freigabe', $sprechtag->titel, betroffener: $lehrer,
                daten: ['sprechtag' => $sprechtag->id, 'freigegeben' => $plan->abgeschlossen_am !== null], ziel: "Elternsprechtag {$sprechtag->id}");
        }

        return redirect()->to($this->zurueck($sprechtag, $lehrer, $request))
            ->with('status', $plan->abgeschlossen_am ? 'Terminplan freigegeben.' : 'Freigabe zurückgenommen.');
    }

    /**
     * Die Lehrkraft sagt einen gebuchten Termin ab – auch nach der Frist. Das
     * Elternteil, das gebucht hat, bekommt eine Mail über den Ausgangskorb.
     */
    public function absagen(Request $request, Termin $termin, VorlagenMailer $mailer): RedirectResponse
    {
        $termin->load(['plan.sprechtag', 'tag', 'kind', 'eltern']);
        $sprechtag = $termin->plan->sprechtag;
        $lehrer = $this->lehrer($request, $sprechtag);
        abort_unless((int) $termin->plan->user_id === $lehrer->id, 404);

        if (! Rechte::istVerwaltung($request->user()) && $termin->beginn()->isPast()) {
            return redirect()->to($this->zurueck($sprechtag, $lehrer, $request))->with('error', 'Der Termin hat schon begonnen.');
        }

        $text = $termin->beschriftung();
        // Die Verwaltung kann still absagen (z. B. nach Rücksprache am Telefon).
        $ohneMail = Rechte::istVerwaltung($request->user()) && $request->boolean('ohne_mail');
        $benachrichtigt = $this->terminAbsagen($termin, $sprechtag, $lehrer, $mailer, ! $ohneMail);

        return redirect()->to($this->zurueck($sprechtag, $lehrer, $request))
            ->with('status', 'Termin abgesagt: '.$text.'.'.($ohneMail ? ' Es wurde keine Mail verschickt.' : ($benachrichtigt
                ? ' Die Eltern bekommen eine Mail.'
                : ' Für die Eltern ist keine Mailadresse hinterlegt – bitte selbst Bescheid geben.')));
    }

    /**
     * Einheitliches Raster: einen Termin als frei, Pause, gesperrt oder Reserve
     * markieren – bis zum Ende des Sprechtags, auch nach Frist und Veröffentlichung
     * (kurzfristige kollegiale Gespräche). Gebuchte Termine bleiben unberührt.
     */
    public function markieren(Request $request, Sprechtag $sprechtag): \Illuminate\Http\JsonResponse
    {
        $lehrer = $this->lehrer($request, $sprechtag);
        abort_unless($sprechtag->einheitlich(), 404);
        abort_unless(Rechte::darfAbsagen($request->user(), $sprechtag, $lehrer), 403, 'Der Sprechtag ist vorbei.');

        $daten = $request->validate([
            'tag_id' => ['required', 'integer'],
            'von' => ['required', 'date_format:H:i'],
            'status' => ['required', Rule::in(array_keys(Slots::MARKIERBAR))],
        ]);
        // Plan bzw. Tag gibt es noch nicht (z. B. Handeln als, Tag später angelegt): jetzt anlegen.
        $tag = $sprechtag->tage()->findOrFail((int) $daten['tag_id']);
        $plan = Plan::firstOrCreate(['sprechtag_id' => $sprechtag->id, 'user_id' => $lehrer->id], [
            'klassen' => Plan::klassenVon(null, $lehrer, $sprechtag),
            'slot_minuten' => $sprechtag->slot_minuten,
            'vorbereitung_minuten' => 0,
        ]);
        $plan->tage()->firstOrCreate(['tag_id' => $tag->id], ['von' => $tag->standard_von ?? '08:00', 'bis' => $tag->standard_bis ?? '15:00', 'pausen' => []]);
        $plan->load(['tage.tag', 'sprechtag']);
        $pt = $plan->tage->firstWhere('tag_id', $tag->id);
        abort_if(! collect(Slots::raster($plan, $pt))->contains('von', $daten['von']), 422, 'Diese Uhrzeit gibt es im Raster nicht.');

        if (Termin::where(['plan_id' => $plan->id, 'tag_id' => $pt->tag_id, 'von' => $daten['von']])->exists()) {
            return response()->json(['message' => 'Der Termin ist schon gebucht – bitte erst absagen.'], 422);
        }
        if (collect(Slots::raster($plan, $pt))->firstWhere('von', $daten['von'])['status'] === 'aus') {
            return response()->json(['message' => 'Die Uhrzeit liegt außerhalb deiner Zeiten – bitte Beginn bzw. Ende anpassen.'], 422);
        }
        if (collect(Slots::raster($plan, $pt))->firstWhere('von', $daten['von'])['status'] === 'dazu') {
            return response()->json(['message' => 'Zu der Zeit bist du bei einer Kollegin dazugebucht – das bitte dort zurücknehmen.'], 422);
        }

        $markiert = (array) ($pt->markierungen ?? []);
        if ($daten['status'] === 'frei') {
            unset($markiert[$daten['von']]);
        } else {
            $markiert[$daten['von']] = $daten['status'];
        }
        ksort($markiert);
        $pt->update(['markierungen' => $markiert ?: null]);
        \Intranet\Modules\Elternsprechtag\Support\Lehrerinfo::pruefen([$plan->id]);

        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben('elternsprechtag.markiert', $sprechtag->titel, betroffener: $lehrer,
                daten: ['sprechtag' => $sprechtag->id, 'slot' => $pt->tag_id.'|'.$daten['von'], 'status' => $daten['status']],
                ziel: "Elternsprechtag {$sprechtag->id}");
        }

        return response()->json(['ok' => true, 'status' => $daten['status']]);
    }

    /**
     * Klassenlehrkraft kommt zu einem Termin einer Kollegin dazu (bzw. nimmt das
     * zurück). Erlaubt für Klassenlehrkräfte der Klasse des Kindes, bis zum Ende
     * des Sprechtags. Eigene Termine zur selben Zeit verhindern das Dazukommen.
     */
    public function dazu(Request $request, Termin $termin): RedirectResponse
    {
        $termin->load(['plan.sprechtag', 'plan.user', 'tag', 'kind.roles']);
        $sprechtag = $termin->plan->sprechtag;
        $lehrer = $this->lehrer($request, $sprechtag);
        $zurueck = redirect()->to($this->zurueck($sprechtag, $lehrer, $request))->with('elternsprechtag_klasse_offen', true);

        abort_if((int) $termin->plan->user_id === $lehrer->id, 404);
        $klassen = array_intersect(Klassen::vonKind($termin->kind), $sprechtag->klassenListe());
        abort_unless(Klassen::alsKlassenlehrer($lehrer, $klassen) !== [] || Rechte::istVerwaltung($request->user()), 403);
        abort_unless(Rechte::darfAbsagen($request->user(), $sprechtag, $lehrer), 403, 'Der Sprechtag ist vorbei.');

        $gruppe = $termin->gruppeTermine();
        if ($request->isMethod('delete')) {
            $termin->dazu()->detach($lehrer->id);
            $text = 'Du kommst nicht mehr dazu: ';
        } else {
            // Eigener Termin zur selben Zeit? Dann geht es nicht.
            $eigen = Termin::where('tag_id', $termin->tag_id)
                ->whereHas('plan', fn ($q) => $q->where(['sprechtag_id' => $sprechtag->id, 'user_id' => $lehrer->id]))->get()
                ->first(fn (Termin $t) => \Intranet\Modules\Elternsprechtag\Support\Buchung::ueberschneidet((int) $termin->tag_id, $termin->von, $termin->bis, collect([$t])));
            if ($eigen) {
                return $zurueck->with('error', 'Zu der Zeit hast du selbst einen Termin ('.$eigen->von.'–'.$eigen->bis.' Uhr).');
            }
            if ($gruppe->contains(fn (Termin $t) => (int) $t->plan?->user_id === $lehrer->id)) {
                return $zurueck->with('error', 'Das ist ein gemeinsames Gespräch, bei dem du ohnehin dabei bist.');
            }
            $termin->dazu()->syncWithoutDetaching([$lehrer->id]);
            $text = 'Du kommst dazu: ';
        }

        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben('elternsprechtag.dazu', $sprechtag->titel, betroffener: $lehrer,
                daten: ['sprechtag' => $sprechtag->id, 'termin' => $termin->id, 'bei' => $termin->plan->user_id, 'dazu' => ! $request->isMethod('delete')],
                ziel: "Elternsprechtag {$sprechtag->id}");
        }

        return $zurueck->with('status', $text.$termin->beschriftung().' bei '.Namen::anzeige($termin->plan->user?->name).' ('.Namen::anzeige($termin->kind?->name).').');
    }

    /**
     * Gemeinsames Gespräch: Raum auf den einer der beteiligten Lehrkräfte setzen –
     * für alle Termine der Gruppe. Dürfen die beteiligten Lehrkräfte und die Verwaltung.
     */
    public function raum(Request $request, Termin $termin): RedirectResponse
    {
        $termin->load(['plan.sprechtag', 'kind.roles']);
        $sprechtag = $termin->plan->sprechtag;
        $lehrer = $this->lehrer($request, $sprechtag);
        abort_if($termin->gruppe === null, 404);
        $gruppe = $termin->gruppeTermine()->load('plan');
        abort_unless($gruppe->contains(fn (Termin $t) => (int) $t->plan?->user_id === $lehrer->id), 404);

        $raeume = $gruppe->map(fn (Termin $t) => $t->plan?->raum)->filter()->unique()->values()->all();
        $raum = (string) $request->validate(['raum' => ['required', 'string', Rule::in($raeume)]])['raum'];
        // Abweichend, wenn nicht der Standardraum der Klasse – dann überschreibt eine spätere Raumwahl ihn nicht.
        $klassen = array_values(array_intersect(Klassen::vonKind($termin->kind), $sprechtag->klassenListe()));
        $standard = Team::raum($sprechtag, $klassen, $gruppe->pluck('plan')->filter());
        Termin::where('gruppe', $termin->gruppe)->update(['raum' => $raum, 'raum_abweichend' => $raum !== $standard]);
        \Intranet\Modules\Elternsprechtag\Support\Lehrerinfo::pruefen($gruppe->pluck('plan_id'));

        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben('elternsprechtag.raum', $sprechtag->titel, betroffener: $lehrer,
                daten: ['sprechtag' => $sprechtag->id, 'termin' => $termin->id, 'raum' => $raum], ziel: "Elternsprechtag {$sprechtag->id}");
        }

        return redirect()->to($this->zurueck($sprechtag, $lehrer, $request))
            ->with('status', 'Raum für das gemeinsame Gespräch '.$termin->beschriftung().': '.$raum.'.');
    }

    /**
     * Teilnahme am Sprechtag absagen bzw. wieder zusagen. Beim Absagen (geht bis
     * zum Ende des Sprechtags, auch nach der Freigabe – z. B. bei Krankheit)
     * verschwindet die Lehrkraft für die Eltern, alle gebuchten Termine werden
     * abgesagt und jedes Elternteil bekommt eine Mail mit der Nachricht.
     */
    public function teilnahme(Request $request, Sprechtag $sprechtag, VorlagenMailer $mailer): RedirectResponse
    {
        $lehrer = $this->lehrer($request, $sprechtag);
        $daten = $request->validate([
            'absagen' => ['boolean'],
            'grund' => ['nullable', 'string', 'max:500'],
            'nachricht' => ['nullable', 'string', 'max:500'],
        ]);
        $absagen = (bool) ($daten['absagen'] ?? false);
        abort_unless($absagen
            ? Rechte::darfAbsagen($request->user(), $sprechtag, $lehrer)
            : Rechte::darfPlanAendern($request->user(), $sprechtag, $lehrer), 403, 'Der Terminplan kann nicht mehr geändert werden.');

        $plan = Plan::with(['termine.tag', 'termine.kind', 'termine.eltern'])
            ->firstOrNew(['sprechtag_id' => $sprechtag->id, 'user_id' => $lehrer->id], ['slot_minuten' => 15, 'vorbereitung_minuten' => 0]);

        $ohneMail = 0;
        if ($absagen) {
            $plan->fill(['abgesagt_am' => now(), 'absage_grund' => $daten['grund'] ?? null, 'abgeschlossen_am' => null])->save();
            $ohneMail = $this->alleAbsagen($plan, $sprechtag, $lehrer, $mailer, (string) ($daten['nachricht'] ?? ''));
        } elseif ($plan->exists) {
            $plan->update(['abgesagt_am' => null, 'absage_grund' => null]);
        }

        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben('elternsprechtag.teilnahme', $sprechtag->titel, betroffener: $lehrer,
                daten: ['sprechtag' => $sprechtag->id, 'abgesagt' => $absagen, 'termine' => $absagen ? $plan->termine->count() : 0],
                ziel: "Elternsprechtag {$sprechtag->id}");
        }

        $meldung = ! $absagen ? 'Teilnahme wieder zugesagt. Bitte den Plan prüfen und freigeben.'
            : 'Teilnahme abgesagt – Eltern können bei '.($lehrer->is($request->user()) ? 'dir' : 'der Lehrkraft').' nichts mehr buchen.'.($plan->termine->isNotEmpty() ? ($plan->termine->count() === 1 ? ' Der gebuchte Termin wurde' : ' Die '.$plan->termine->count().' gebuchten Termine wurden').' abgesagt, die Eltern bekommen eine Mail.' : '')
                .($ohneMail > 0 ? " {$ohneMail} Eltern ohne Mailadresse – bitte selbst Bescheid geben." : '');

        return redirect()->to($this->zurueck($sprechtag, $lehrer, $request))->with('status', $meldung);
    }

    /**
     * Alle Termine des Plans absagen und je Elternteil EINE Mail mit allen
     * betroffenen Terminen und der Nachricht schicken. Liefert die Zahl der
     * Elternteile, die keine Mail bekommen konnten.
     */
    private function alleAbsagen(Plan $plan, Sprechtag $sprechtag, User $lehrer, VorlagenMailer $mailer, string $nachricht): int
    {
        $termine = $plan->termine;
        $planIds = collect([$plan->id]);
        $mitMail = Ausfall::jeEltern($termine);

        foreach ($termine as $termin) {
            $planIds = $planIds->concat($termin->gruppeTermine()->pluck('plan_id'));
            $termin->absagenMitGruppe();
        }
        \Intranet\Modules\Elternsprechtag\Support\Lehrerinfo::pruefen($planIds);

        $erreicht = [];
        foreach ($mitMail as $eintrag) {
            $eltern = $eintrag['eltern'];
            [$html, $text] = Ausfall::werte($sprechtag, $eltern, $lehrer->name, $eintrag['termine'], $nachricht);
            try {
                $mailer->senden(
                    ElternsprechtagServiceProvider::VORLAGE_AUSFALL, $eltern->email, $html, $text,
                    quelle: 'Elternsprechtag-Ausfall', referenz: 'elternsprechtag-ausfall:'.$plan->id.':'.$eltern->id, modul: 'Elternsprechtag',
                );
                $erreicht[$eltern->id] = true;
            } catch (\Throwable $e) {
                Log::warning('Elternsprechtag-Ausfall nicht verschickt.', ['user' => $eltern->id, 'fehler' => $e->getMessage()]);
            }
        }

        foreach ($termine as $termin) {
            if (class_exists(\App\Support\Audit::class)) {
                \App\Support\Audit::schreiben('elternsprechtag.absage_lehrer', $sprechtag->titel, betroffener: $termin->eltern,
                    daten: ['sprechtag' => $sprechtag->id, 'lehrer' => $lehrer->id, 'kind' => $termin->kind_user_id, 'mail' => isset($erreicht[$termin->eltern_user_id]), 'ausfall' => true],
                    ziel: "Elternsprechtag {$sprechtag->id}");
            }
        }

        return $termine->pluck('eltern_user_id')->unique()->reject(fn ($id) => isset($erreicht[$id]))->count();
    }

    /**
     * Termin löschen – bei einem gemeinsamen Gespräch für alle Klassenlehrer –
     * und die Eltern per Mail benachrichtigen; true, wenn die Mail rausging.
     */
    private function terminAbsagen(Termin $termin, Sprechtag $sprechtag, User $lehrer, VorlagenMailer $mailer, bool $mailen = true): bool
    {
        $text = $termin->beschriftung();
        $gruppe = $termin->gruppeTermine();
        $namen = $gruppe->map(fn (Termin $t) => $t->plan?->user?->name)->filter()->join(' und ') ?: $lehrer->name;
        $termin->absagenMitGruppe();
        \Intranet\Modules\Elternsprechtag\Support\Lehrerinfo::pruefen($gruppe->pluck('plan_id'), $mailen);

        $eltern = $termin->eltern;
        $benachrichtigt = false;
        if ($mailen && $eltern && ! $eltern->istGesperrt() && filled($eltern->email)) {
            $werte = [
                'name' => $eltern->name,
                'sprechtag' => $sprechtag->titel,
                'lehrer' => $namen,
                'kind' => (string) $termin->kind?->name,
                'termin' => $text,
                'link' => route('module.elternsprechtag.eltern.index'),
            ];
            try {
                $mailer->senden(
                    ElternsprechtagServiceProvider::VORLAGE_ABSAGE, $eltern->email, array_map('e', $werte), $werte,
                    quelle: 'Elternsprechtag-Absage', referenz: 'elternsprechtag-absage:'.$termin->id, modul: 'Elternsprechtag',
                );
                $benachrichtigt = true;
            } catch (\Throwable $e) {
                Log::warning('Elternsprechtag-Absage nicht verschickt.', ['user' => $eltern->id, 'fehler' => $e->getMessage()]);
            }
        }

        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben('elternsprechtag.absage_lehrer', $sprechtag->titel, betroffener: $eltern,
                daten: ['sprechtag' => $sprechtag->id, 'lehrer' => $lehrer->id, 'kind' => $termin->kind_user_id, 'mail' => $benachrichtigt],
                ziel: "Elternsprechtag {$sprechtag->id}");
        }

        return $benachrichtigt;
    }

    /**
     * Wessen Plan? Der eigene – oder für die Verwaltung der über `?lehrer=`.
     * Wer in keiner Klasse des Sprechtags unterrichtet, hat dort keinen Plan.
     */
    private function lehrer(Request $request, Sprechtag $sprechtag): User
    {
        $user = $request->user();
        $lehrer = $user;

        if ($request->filled('lehrer') && (int) $request->input('lehrer') !== $user->id) {
            abort_unless(Rechte::istVerwaltung($user), 403);
            $lehrer = User::with('roles')->findOrFail((int) $request->input('lehrer'));
        }

        abort_unless(Rechte::istLehrerIn($lehrer, $sprechtag), 404, 'In keiner Klasse als Lehrer eingetragen.');

        return $lehrer;
    }

    private function zurueck(Sprechtag $sprechtag, User $lehrer, Request $request): string
    {
        return route('module.elternsprechtag.plan.zeigen', $lehrer->is($request->user())
            ? [$sprechtag]
            : [$sprechtag, 'lehrer' => $lehrer->id]);
    }
}
