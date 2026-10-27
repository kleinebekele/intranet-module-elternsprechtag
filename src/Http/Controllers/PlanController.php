<?php

namespace Intranet\Modules\Elternsprechtag\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Support\Klassen;
use Intranet\Modules\Elternsprechtag\Support\Rechte;
use Intranet\Modules\Elternsprechtag\Support\Slots;

/**
 * Terminplan eines Lehrers: Zeiten je Tag, Pausen, Gesprächslänge,
 * Vorbereitung und die Eltern, mit denen er unbedingt sprechen will.
 */
class PlanController
{
    /** Die Sprechtage, an denen der Benutzer als Lehrer beteiligt ist. */
    public function index(Request $request): View
    {
        $user = $request->user();
        $eigene = Klassen::vonLehrer($user);

        $sprechtage = Sprechtag::with('tage')->get()
            ->reject->vorbei()
            ->filter(fn (Sprechtag $s) => array_intersect($eigene, $s->klassenListe()) !== [])
            ->sortBy(fn (Sprechtag $s) => $s->tage->first()?->datum)
            ->values();

        $plaene = Plan::with('tage')->where('user_id', $user->id)
            ->whereIn('sprechtag_id', $sprechtage->pluck('id'))
            ->get()->keyBy('sprechtag_id');

        return view('elternsprechtag::plan.index', [
            'sprechtage' => $sprechtage,
            'plaene' => $plaene,
            'eigene' => $eigene,
        ]);
    }

    public function zeigen(Request $request, Sprechtag $sprechtag): View
    {
        $lehrer = $this->lehrer($request, $sprechtag);
        $sprechtag->load('tage');

        $plan = Plan::with(['tage', 'wuensche'])->firstOrNew(['sprechtag_id' => $sprechtag->id, 'user_id' => $lehrer->id]);
        $klassen = $this->klassen($lehrer, $sprechtag);
        $planTage = $plan->tage->keyBy('tag_id');

        return view('elternsprechtag::plan.zeigen', [
            'sprechtag' => $sprechtag,
            'lehrer' => $lehrer,
            'fremd' => ! $lehrer->is($request->user()),
            'plan' => $plan,
            'klassen' => $klassen,
            'planTage' => $planTage,
            'kinder' => Klassen::kinder($klassen),
            'wuensche' => $plan->wuensche->keyBy('kind_user_id'),
            'darfAendern' => Rechte::darfPlanAendern($request->user(), $sprechtag, $lehrer),
        ]);
    }

    public function speichern(Request $request, Sprechtag $sprechtag): RedirectResponse
    {
        $lehrer = $this->lehrer($request, $sprechtag);
        abort_unless(Rechte::darfPlanAendern($request->user(), $sprechtag, $lehrer), 403, 'Die Frist für den Terminplan ist abgelaufen.');

        $daten = $request->validate([
            'slot_minuten' => ['required', 'integer', 'min:5', 'max:120'],
            'vorbereitung_minuten' => ['required', 'integer', 'min:0', 'max:60'],
            'raum' => ['nullable', 'string', 'max:255'],
            'bemerkung' => ['nullable', 'string', 'max:2000'],
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

        // Wünsche nur für Kinder aus den eigenen Klassen dieses Sprechtags.
        $erlaubt = Klassen::kinder($this->klassen($lehrer, $sprechtag))->map(fn ($z) => $z['kind']->id)->all();
        $wuensche = array_values(array_intersect(array_map('intval', $daten['wuensche'] ?? []), $erlaubt));

        DB::transaction(function () use ($sprechtag, $lehrer, $daten, $tagIds, $wuensche) {
            $plan = Plan::firstOrNew(['sprechtag_id' => $sprechtag->id, 'user_id' => $lehrer->id]);
            $plan->fill([
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

        return redirect()->to($this->zurueck($sprechtag, $lehrer, $request))->with('status', 'Terminplan gespeichert.');
    }

    /** „Fertig" melden bzw. wieder öffnen – damit die Verwaltung den Stand sieht. */
    public function abschliessen(Request $request, Sprechtag $sprechtag): RedirectResponse
    {
        $lehrer = $this->lehrer($request, $sprechtag);
        abort_unless(Rechte::darfPlanAendern($request->user(), $sprechtag, $lehrer), 403, 'Die Frist für den Terminplan ist abgelaufen.');

        $plan = Plan::where(['sprechtag_id' => $sprechtag->id, 'user_id' => $lehrer->id])->firstOrFail();
        $plan->update(['abgeschlossen_am' => $plan->abgeschlossen_am ? null : now()]);

        return redirect()->to($this->zurueck($sprechtag, $lehrer, $request))
            ->with('status', $plan->abgeschlossen_am ? 'Terminplan als fertig gemeldet.' : 'Terminplan wieder geöffnet.');
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

        abort_unless(Rechte::istLehrerIn($lehrer, $sprechtag), 404, 'In keiner Klasse dieses Elternsprechtags als Lehrer eingetragen.');

        return $lehrer;
    }

    /** @return list<string> */
    private function klassen(User $lehrer, Sprechtag $sprechtag): array
    {
        $klassen = array_values(array_intersect($sprechtag->klassenListe(), Klassen::vonLehrer($lehrer)));
        natcasesort($klassen);

        return array_values($klassen);
    }

    private function zurueck(Sprechtag $sprechtag, User $lehrer, Request $request): string
    {
        return route('module.elternsprechtag.plan.zeigen', $lehrer->is($request->user())
            ? [$sprechtag]
            : [$sprechtag, 'lehrer' => $lehrer->id]);
    }
}
