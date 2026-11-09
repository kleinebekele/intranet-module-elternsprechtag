<?php

namespace Intranet\Modules\Elternsprechtag\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Intranet\Modules\Elternsprechtag\Models\Klassenteam;
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;

/**
 * Gemeinsame Gespräche, wenn eine Klasse mehrere Klassenlehrer hat.
 *
 * Jeder behält seinen eigenen Plan (Zeiten und Pausen dürfen abweichen); nur
 * Gesprächsdauer und Vorbereitungszeit sind für alle gleich. Gemeinsam buchbar
 * sind die Uhrzeiten, die in allen Plänen genau gleich liegen. Ein gemeinsamer
 * Termin besteht aus je einem Termin pro Plan, verbunden über `gruppe`.
 *
 * Gemeinsam geführt werden – je nach Einstellung der Klasse – nur die Gespräche,
 * die einer der Klassenlehrer wünscht (`wunsch`, Standard), oder alle (`alle`).
 */
class Team
{
    public const MODI = [
        'wunsch' => 'Nur Gespräche, die eine/r von uns wünscht',
        'alle' => 'Alle Gespräche der Klasse',
    ];

    /** @return array<string, string>  Klasse => Modus */
    public static function modi(Sprechtag $sprechtag): array
    {
        return Klassenteam::where('sprechtag_id', $sprechtag->id)->pluck('modus', 'klasse')
            ->mapWithKeys(fn ($m, $k) => [(string) $k => $m])->all();
    }

    /**
     * Die Teams des Sprechtags: je Klasse mit mindestens zwei Klassenlehrern,
     * die teilnehmen, Tage eingetragen und die Klasse gewählt haben.
     *
     * @param  Collection<int, Plan>  $plaene
     * @return array<string, array{modus: string, plaene: Collection<int, Plan>}>
     */
    public static function alle(Sprechtag $sprechtag, Collection $plaene): array
    {
        $modi = self::modi($sprechtag);
        $teams = [];

        foreach ($sprechtag->klassenListe() as $klasse) {
            $mit = $plaene
                ->filter(fn (Plan $p) => $p->user !== null && ! $p->abgesagt() && $p->tage->isNotEmpty()
                    && in_array($klasse, Plan::klassenVon($p, $p->user, $sprechtag), true)
                    && Klassen::alsKlassenlehrer($p->user, [$klasse]) !== [])
                ->sortBy(fn (Plan $p) => Namen::schluessel($p->user->name))
                ->values();

            if ($mit->count() >= 2) {
                $teams[$klasse] = ['modus' => $modi[$klasse] ?? 'wunsch', 'plaene' => $mit];
            }
        }

        return $teams;
    }

    /**
     * Die anderen Klassenlehrer der Klassen, für die $lehrer Klassenlehrer ist.
     *
     * @return array<string, Collection<int, User>>  Klasse => Partner
     */
    public static function partner(User $lehrer, Sprechtag $sprechtag): array
    {
        $partner = [];
        foreach (Klassen::alsKlassenlehrer($lehrer, $sprechtag->klassenListe()) as $klasse) {
            $andere = Klassen::klassenlehrer([$klasse])->reject(fn (User $u) => $u->id === $lehrer->id)
                ->sortBy(fn (User $u) => Namen::schluessel($u->name))->values();
            if ($andere->isNotEmpty()) {
                $partner[$klasse] = $andere;
            }
        }

        return $partner;
    }

    /**
     * Die Slots, die in allen Plänen gleich liegen; frei nur, wenn sie überall frei sind.
     *
     * @param  Collection<int, Plan>  $plaene
     * @return list<array{tag_id: int, tag: string, von: string, bis: string, frei: bool}>
     */
    public static function slots(Collection $plaene, Collection $andere): array
    {
        $je = $plaene->map(fn (Plan $p) => collect(Buchung::slots($p, $andere))
            ->keyBy(fn ($s) => $s['tag_id'].'|'.$s['von'].'|'.$s['bis']))->values();
        $erste = $je->shift();
        if ($erste === null) {
            return [];
        }

        return $erste
            ->filter(fn ($s, $k) => $je->every(fn ($liste) => $liste->has($k)))
            ->map(fn ($s, $k) => [
                'frei' => $s['frei'] && $je->every(fn ($liste) => $liste[$k]['frei']),
                'geblockt' => $s['geblockt'] || $je->contains(fn ($liste) => $liste[$k]['geblockt']),
            ] + $s)
            ->values()->all();
    }

    /**
     * Ausweg, wenn keine gemeinsame Uhrzeit mehr frei ist: die Slots jedes
     * Einzelnen, je mit `plan` (dort wird gebucht) und `nur` (sein Name).
     * Eine Uhrzeit, die bei mehreren frei ist, steht nur einmal da.
     *
     * @param  Collection<int, Plan>  $plaene
     * @return list<array{tag_id: int, tag: string, von: string, bis: string, frei: bool, plan: int, nur: string}>
     */
    public static function einzelSlots(Collection $plaene, Collection $andere): array
    {
        return $plaene
            ->flatMap(fn (Plan $p) => collect(Buchung::slots($p, $andere))
                ->map(fn ($s) => $s + ['plan' => $p->id, 'nur' => Namen::anzeige($p->user->name)]))
            ->sortBy(fn ($s) => [$s['frei'] ? 0 : 1])
            ->unique(fn ($s) => $s['tag_id'].'|'.$s['von'])
            ->sortBy(fn ($s) => sprintf('%010d|%s', $s['tag_id'], $s['von']))
            ->values()->all();
    }

    /** Namen für die Anzeige: „Nachname, Vorname & Nachname, Vorname". */
    public static function namen(Collection $plaene): string
    {
        return $plaene->map(fn (Plan $p) => Namen::anzeige($p->user->name))->join(' & ');
    }
}
