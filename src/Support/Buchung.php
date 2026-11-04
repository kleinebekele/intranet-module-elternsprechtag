<?php

namespace Intranet\Modules\Elternsprechtag\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\PlanTag;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Models\Termin;

/**
 * Was Eltern buchen können: je Kind die Lehrer, die an diesem Sprechtag für
 * die Klasse des Kindes da sind, mit ihren freien Slots.
 *
 * Regeln: je Lehrer und Kind ein Termin, je Slot eine Buchung, und die Termine
 * einer Familie (alle Kinder dieses Elternteils) dürfen sich nicht überschneiden.
 */
class Buchung
{
    /**
     * Eltern buchen selbst, sobald der Sprechtag veröffentlicht ist und bis er
     * vorbei ist. Persönlich eingeladene Eltern sofort, alle anderen erst nach
     * dem Vorsprung (Sprechtag::fuerAlleOffen()).
     */
    public static function offen(Sprechtag $sprechtag, ?User $eltern = null): bool
    {
        if (! $sprechtag->veroeffentlicht() || $sprechtag->vorbei()) {
            return false;
        }

        return $sprechtag->fuerAlleOffen() || ($eltern !== null && InfoMail::einladungen($sprechtag)->has($eltern->id));
    }

    /** Kinder des Elternteils, die in einer Klasse des Sprechtags sind. */
    public static function kinder(User $eltern, Sprechtag $sprechtag): Collection
    {
        return $eltern->children()->with('roles')->get()
            ->sortBy(fn (User $k) => Namen::schluessel($k->name))
            ->map(fn (User $k) => ['kind' => $k, 'klassen' => array_values(array_intersect(Klassen::vonKind($k), $sprechtag->klassenListe()))])
            ->filter(fn (array $z) => $z['klassen'] !== [])
            ->values();
    }

    /**
     * Je Kind die Lehrer mit Termin, Gesprächswunsch und Slots – alle Pläne des
     * Sprechtags außer denen, deren Lehrer abgesagt hat. Wann Eltern das sehen,
     * regelt offen() (Veröffentlichung des Sprechtags).
     *
     * @return Collection<int, array{kind: User, klassen: list<string>, lehrer: Collection}>
     */
    public static function angebot(User $eltern, Sprechtag $sprechtag): Collection
    {
        $kinder = self::kinder($eltern, $sprechtag);
        if ($kinder->isEmpty()) {
            return $kinder;
        }

        $plaene = Plan::with(['user.roles', 'tage.tag', 'termine', 'wuensche'])
            ->where('sprechtag_id', $sprechtag->id)
            ->get()
            ->filter(fn (Plan $p) => $p->user !== null && $p->tage->isNotEmpty() && ! $p->abgesagt());

        $familie = self::familienTermine($kinder->pluck('kind.id')->all(), $sprechtag);
        $teams = Team::alle($sprechtag, $plaene);

        return $kinder->map(function (array $z) use ($plaene, $sprechtag, $familie, $teams) {
            $kid = $z['kind']->id;
            $team = self::team($z, $teams);

            $lehrer = $plaene
                ->filter(fn (Plan $p) => array_intersect($z['klassen'], Plan::klassenVon($p, $p->user, $sprechtag)) !== [])
                ->reject(fn (Plan $p) => $team && $team->contains('id', $p->id))
                ->sortBy(fn (Plan $p) => (Klassen::istKlassenlehrer($p->user, $z['klassen']) ? '0' : '1').Namen::schluessel($p->user->name))
                ->map(function (Plan $p) use ($kid, $familie, $z) {
                    $termin = $p->termine->firstWhere('kind_user_id', $kid);
                    $andere = $familie->reject(fn (Termin $t) => $termin && $t->id === $termin->id);

                    return [
                        'plan' => $p,
                        'team' => null,
                        'namen' => Namen::anzeige($p->user->name),
                        'termin' => $termin,
                        'klassenlehrer' => Klassen::istKlassenlehrer($p->user, $z['klassen']),
                        'wunsch' => $p->wuensche->firstWhere('kind_user_id', $kid),
                        'slots' => self::slots($p, $andere),
                        'ausweichen' => false,
                    ];
                })
                ->values();

            if ($team) {
                $termine = $team->map(fn (Plan $p) => $p->termine->firstWhere('kind_user_id', $kid))->filter();
                $andere = $familie->reject(fn (Termin $t) => $termine->contains('id', $t->id));
                $slots = Team::slots($team, $andere);
                // Keine gemeinsame Uhrzeit mehr frei: die freien Zeiten jedes
                // Einzelnen anbieten – gebucht wird dann nur bei diesem.
                $ausweichen = ! collect($slots)->contains('frei', true);
                if ($ausweichen) {
                    $slots = Team::einzelSlots($team, $andere);
                }
                $lehrer->prepend([
                    'plan' => $team->first(),
                    'team' => $team,
                    'namen' => Team::namen($team),
                    'termin' => $termine->first(),
                    'klassenlehrer' => true,
                    'wunsch' => $team->map(fn (Plan $p) => $p->wuensche->firstWhere('kind_user_id', $kid))->filter()->first(),
                    'slots' => $slots,
                    'ausweichen' => $ausweichen,
                ]);
            }

            return $z + ['lehrer' => $lehrer];
        });
    }

    /**
     * Die Klassenlehrer-Pläne, wenn das Gespräch über dieses Kind gemeinsam
     * geführt wird (Modus `alle` oder ein Gesprächswunsch eines von ihnen).
     *
     * @param  array<string, array{modus: string, plaene: Collection<int, Plan>}>  $teams
     * @return Collection<int, Plan>|null
     */
    private static function team(array $z, array $teams): ?Collection
    {
        $kid = $z['kind']->id;
        foreach ($z['klassen'] as $klasse) {
            $t = $teams[$klasse] ?? null;
            if ($t && ($t['modus'] === 'alle' || $t['plaene']->contains(fn (Plan $p) => $p->wuensche->contains('kind_user_id', $kid)))) {
                return $t['plaene'];
            }
        }

        return null;
    }

    /**
     * Alle Slots eines Plans, je mit „frei" (nicht vergeben, keine Überschneidung
     * mit einem anderen Termin der Familie, noch nicht begonnen).
     *
     * @param  Collection<int, Termin>  $andere
     * @return list<array{tag_id: int, tag: string, von: string, bis: string, frei: bool}>
     */
    public static function slots(Plan $plan, Collection $andere): array
    {
        $liste = [];
        foreach ($plan->tage->sortBy(fn (PlanTag $pt) => $pt->tag?->datum) as $pt) {
            if ($pt->tag === null) {
                continue;
            }
            $vergeben = $plan->termine->where('tag_id', $pt->tag_id)->pluck('von')->all();
            foreach (Slots::fuer($plan, $pt) as $s) {
                $liste[] = [
                    'tag_id' => (int) $pt->tag_id,
                    'tag' => $pt->tag->beschriftung(),
                    'von' => $s['von'],
                    'bis' => $s['bis'],
                    'frei' => ! in_array($s['von'], $vergeben, true)
                        && ! self::ueberschneidet((int) $pt->tag_id, $s['von'], $s['bis'], $andere)
                        && $pt->tag->datum->copy()->setTimeFromTimeString($s['von'])->isFuture(),
                ];
            }
        }

        return $liste;
    }

    /** @param  list<int>  $kindIds */
    public static function familienTermine(array $kindIds, Sprechtag $sprechtag): Collection
    {
        return Termin::whereIn('kind_user_id', $kindIds)
            ->whereHas('plan', fn ($q) => $q->where('sprechtag_id', $sprechtag->id))
            ->get();
    }

    public static function ueberschneidet(int $tagId, string $von, string $bis, Collection $termine): bool
    {
        $a = Slots::minuten($von);
        $e = Slots::minuten($bis);

        return $termine->contains(fn (Termin $t) => (int) $t->tag_id === $tagId
            && $a < Slots::minuten($t->bis) && $e > Slots::minuten($t->von));
    }
}
