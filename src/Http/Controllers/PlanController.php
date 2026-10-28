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
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Models\Termin;
use Intranet\Modules\Elternsprechtag\Support\Klassen;
use Intranet\Modules\Elternsprechtag\Support\Rechte;
use Intranet\Modules\Elternsprechtag\Support\Slots;

/**
 * Terminplan eines Lehrers: Zeiten je Tag, Pausen, Gesprächslänge,
 * Vorbereitung und die Eltern, mit denen er unbedingt sprechen will.
 */
class PlanController
{
    /** Die kommenden Sprechtage – für jeden Lehrer, welche Klassen er übernimmt, wählt er im Plan. */
    public function index(Request $request): View
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
            ->sortBy(fn (User $u) => mb_strtolower($u->name))
            ->values();
    }

    public function zeigen(Request $request, Sprechtag $sprechtag): View
    {
        $lehrer = $this->lehrer($request, $sprechtag);
        $sprechtag->load('tage');

        $plan = Plan::with(['tage', 'wuensche', 'termine.tag', 'termine.kind', 'termine.eltern'])->firstOrNew(['sprechtag_id' => $sprechtag->id, 'user_id' => $lehrer->id]);
        $klassen = Plan::klassenVon($plan->exists ? $plan : null, $lehrer, $sprechtag);
        $planTage = $plan->tage->keyBy('tag_id');

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
            'verwaltung' => Rechte::istVerwaltung($request->user()),
            'personen' => $this->allePersonen($request),
        ]);
    }

    public function speichern(Request $request, Sprechtag $sprechtag): RedirectResponse
    {
        $lehrer = $this->lehrer($request, $sprechtag);
        abort_unless(Rechte::darfPlanAendern($request->user(), $sprechtag, $lehrer), 403, 'Der Terminplan kann nicht mehr geändert werden.');

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

        $klassen = array_values(array_unique($daten['klassen'] ?? []));

        // Wünsche nur für Kinder aus den gewählten Klassen.
        $erlaubt = Klassen::kinder($klassen)->map(fn ($z) => $z['kind']->id)->all();
        $wuensche = array_values(array_intersect(array_map('intval', $daten['wuensche'] ?? []), $erlaubt));

        DB::transaction(function () use ($sprechtag, $lehrer, $daten, $tagIds, $wuensche, $klassen, $request) {
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

                $plan->tage()->updateOrCreate(['tag_id' => $tagId], ['von' => $t['von'], 'bis' => $t['bis'], 'pausen' => $pausen]);
            }

            $plan->wuensche()->whereNotIn('kind_user_id', $wuensche)->delete();
            foreach ($wuensche as $kindId) {
                $plan->wuensche()->updateOrCreate(['kind_user_id' => $kindId], ['notiz' => $daten['notiz'][$kindId] ?? null]);
            }
        });

        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben('elternsprechtag.plan', $sprechtag->titel, betroffener: $lehrer,
                daten: ['sprechtag' => $sprechtag->id, 'wuensche' => count($wuensche)], ziel: "Elternsprechtag {$sprechtag->id}");
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
        $benachrichtigt = $this->terminAbsagen($termin, $sprechtag, $lehrer, $mailer);

        return redirect()->to($this->zurueck($sprechtag, $lehrer, $request))
            ->with('status', 'Termin abgesagt: '.$text.'.'.($benachrichtigt
                ? ' Die Eltern bekommen eine Mail.'
                : ' Für die Eltern ist keine Mailadresse hinterlegt – bitte selbst Bescheid geben.'));
    }

    /**
     * Teilnahme am Sprechtag absagen bzw. wieder zusagen. Beim Absagen wird der
     * Plan zurückgezogen und alle gebuchten Termine werden mit Mail abgesagt.
     */
    public function teilnahme(Request $request, Sprechtag $sprechtag, VorlagenMailer $mailer): RedirectResponse
    {
        $lehrer = $this->lehrer($request, $sprechtag);
        abort_unless(Rechte::darfPlanAendern($request->user(), $sprechtag, $lehrer), 403, 'Der Terminplan kann nicht mehr geändert werden.');

        $daten = $request->validate(['absagen' => ['boolean'], 'grund' => ['nullable', 'string', 'max:500']]);
        $plan = Plan::with(['termine.tag', 'termine.kind', 'termine.eltern'])
            ->firstOrNew(['sprechtag_id' => $sprechtag->id, 'user_id' => $lehrer->id], ['slot_minuten' => 15, 'vorbereitung_minuten' => 0]);
        $absagen = (bool) ($daten['absagen'] ?? false);

        $ohneMail = 0;
        if ($absagen) {
            $plan->fill(['abgesagt_am' => now(), 'absage_grund' => $daten['grund'] ?? null, 'abgeschlossen_am' => null])->save();
            foreach ($plan->termine as $termin) {
                $ohneMail += $this->terminAbsagen($termin->setRelation('plan', $plan), $sprechtag, $lehrer, $mailer) ? 0 : 1;
            }
        } elseif ($plan->exists) {
            $plan->update(['abgesagt_am' => null, 'absage_grund' => null]);
        }

        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::schreiben('elternsprechtag.teilnahme', $sprechtag->titel, betroffener: $lehrer,
                daten: ['sprechtag' => $sprechtag->id, 'abgesagt' => $absagen, 'termine' => $absagen ? $plan->termine->count() : 0],
                ziel: "Elternsprechtag {$sprechtag->id}");
        }

        $meldung = ! $absagen ? 'Teilnahme wieder zugesagt. Bitte den Plan prüfen und freigeben.'
            : 'Teilnahme abgesagt.'.($plan->termine->isNotEmpty() ? ($plan->termine->count() === 1 ? ' Der gebuchte Termin wurde' : ' Die '.$plan->termine->count().' gebuchten Termine wurden').' abgesagt, die Eltern bekommen eine Mail.' : '')
                .($ohneMail > 0 ? " {$ohneMail} Eltern ohne Mailadresse – bitte selbst Bescheid geben." : '');

        return redirect()->to($this->zurueck($sprechtag, $lehrer, $request))->with('status', $meldung);
    }

    /** Termin löschen, Eltern per Mail benachrichtigen; true, wenn die Mail rausging. */
    private function terminAbsagen(Termin $termin, Sprechtag $sprechtag, User $lehrer, VorlagenMailer $mailer): bool
    {
        $text = $termin->beschriftung();
        $termin->delete();

        $eltern = $termin->eltern;
        $benachrichtigt = false;
        if ($eltern && ! $eltern->istGesperrt() && filled($eltern->email)) {
            $werte = [
                'name' => $eltern->name,
                'sprechtag' => $sprechtag->titel,
                'lehrer' => $lehrer->name,
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
