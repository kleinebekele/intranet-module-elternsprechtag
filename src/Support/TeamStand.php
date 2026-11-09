<?php

namespace Intranet\Modules\Elternsprechtag\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Models\Termin;

/**
 * Übersicht für die Verwaltung: Klassen mit mehreren Klassenlehrkräften – ob
 * das gemeinsame Gespräch zustande kommt (wenn nicht: warum) und welche
 * Termine dieser Klassen nur bei einer der Lehrkräfte liegen.
 */
class TeamStand
{
    /**
     * @param  Collection<int, Plan>  $plaene  alle Pläne des Sprechtags (mit tage, termine, wuensche, user.roles)
     * @return list<array{klasse: string, modus: string, team: bool, lehrer: list<array{name: string, grund: ?string}>, einzeln: list<array>}>
     */
    public static function fuer(Sprechtag $sprechtag, Collection $plaene): array
    {
        $plaene = $plaene->keyBy('user_id');
        $teams = Team::alle($sprechtag, $plaene->values());
        $modi = Team::modi($sprechtag);
        $liste = [];

        foreach ($sprechtag->klassenListe() as $klasse) {
            $kl = Klassen::klassenlehrer([$klasse])->sortBy(fn (User $u) => Namen::schluessel($u->name))->values();
            if ($kl->count() < 2) {
                continue;
            }

            $lehrer = $kl->map(fn (User $u) => [
                'name' => Namen::anzeige($u->name),
                'grund' => self::grund($plaene->get($u->id), $u, $sprechtag, $klasse),
            ])->all();

            // Termine von Kindern dieser Klasse bei genau einer Klassenlehrkraft.
            $klPlaene = $kl->map(fn (User $u) => $plaene->get($u->id))->filter();
            $einzeln = Termin::with(['kind.roles', 'eltern', 'tag', 'plan.user'])
                ->whereIn('plan_id', $klPlaene->pluck('id'))
                ->whereNull('gruppe')
                ->get()
                ->filter(fn (Termin $t) => $t->kind && in_array($klasse, Klassen::vonKind($t->kind), true))
                ->sortBy(fn (Termin $t) => Namen::schluessel($t->kind->name))
                ->map(fn (Termin $t) => [
                    'kind' => Namen::anzeige($t->kind->name),
                    'eltern' => $t->eltern,
                    'lehrer' => Namen::anzeige($t->plan?->user?->name),
                    'termin' => $t->tag?->beschriftung().', '.$t->von.' Uhr',
                    'grund' => match (true) {
                        $t->allein => 'kein gemeinsamer Termin mehr frei',
                        ! isset($teams[$klasse]) => 'kein gemeinsames Gespräch möglich (siehe oben)',
                        ($teams[$klasse]['modus'] ?? 'wunsch') === 'wunsch' && ! $klPlaene->contains(fn (Plan $p) => $p->wuensche->contains('kind_user_id', $t->kind_user_id))
                            => 'kein Gesprächswunsch – gemeinsam nur bei Wunsch',
                        default => 'gebucht, bevor das gemeinsame Gespräch möglich war',
                    },
                ])->values()->all();

            $liste[] = [
                'klasse' => $klasse,
                'modus' => $modi[$klasse] ?? 'wunsch',
                'team' => isset($teams[$klasse]),
                'lehrer' => $lehrer,
                'einzeln' => $einzeln,
            ];
        }

        return $liste;
    }

    /** Warum diese Klassenlehrkraft (noch) nicht beim gemeinsamen Gespräch dabei ist – null = dabei. */
    private static function grund(?Plan $plan, User $lehrer, Sprechtag $sprechtag, string $klasse): ?string
    {
        return match (true) {
            $plan === null => 'noch kein Plan',
            $plan->abgesagt() => 'nimmt nicht teil',
            $plan->tage->isEmpty() => 'keine Tage eingetragen',
            ! in_array($klasse, Plan::klassenVon($plan, $lehrer, $sprechtag), true) => 'Klasse '.$klasse.' nicht gewählt',
            default => null,
        };
    }
}
