<?php

namespace Intranet\Modules\Elternsprechtag\Support;

use App\Models\User;
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Models\Termin;

/**
 * Für die Verwaltung: Warum ist ein Gespräch (nicht) gemeinsam buchbar, und
 * woran scheitert eine bestimmte Uhrzeit? Je Uhrzeit der Stand bei jeder
 * Klassenlehrkraft und in der Familie (Termine der Geschwister).
 */
class Diagnose
{
    /**
     * @return array{hinweise: list<string>, lehrer: list<string>, zeilen: list<array{tag: string, zeit: string, je: list<string>, familie: string, gemeinsam: bool}>}
     */
    public static function gespraech(User $eltern, Sprechtag $sprechtag, int $kindId): array
    {
        $aus = ['hinweise' => [], 'lehrer' => [], 'zeilen' => []];
        $z = Buchung::kinder($eltern, $sprechtag)->first(fn ($z) => $z['kind']->id === $kindId);
        if (! $z) {
            $aus['hinweise'][] = 'Das Kind ist diesem Elternteil nicht (mehr) zugeordnet oder in keiner Klasse des Sprechtags.';

            return $aus;
        }

        $plaene = Plan::with(['user.roles', 'tage.tag', 'termine.kind', 'wuensche', 'blockaden'])
            ->where('sprechtag_id', $sprechtag->id)->get()
            ->filter(fn (Plan $p) => $p->user !== null && $p->tage->isNotEmpty() && ! $p->abgesagt());
        $teams = Team::alle($sprechtag, $plaene);
        $klassen = $z['klassen'];
        $team = collect($teams)->first(fn ($t, $k) => in_array((string) $k, $klassen, true));

        if (! $team) {
            $aus['hinweise'][] = 'Für Klasse '.implode(', ', $klassen).' gibt es kein gemeinsames Gespräch (es nehmen keine zwei Klassenlehrkräfte mit eingetragenen Tagen teil, die diese Klasse gewählt haben).';

            return $aus;
        }

        $wunsch = $team['plaene']->filter(fn (Plan $p) => $p->wuensche->contains('kind_user_id', $kindId));
        $aus['hinweise'][] = 'Einstellung der Klasse: '.($team['modus'] === 'alle' ? 'alle Gespräche gemeinsam' : 'nur gewünschte Gespräche gemeinsam').'.';
        $aus['hinweise'][] = $wunsch->isNotEmpty()
            ? 'Gesprächswunsch von: '.$wunsch->map(fn (Plan $p) => Namen::anzeige($p->user->name))->join(', ').' → gemeinsames Gespräch.'
            : ($team['modus'] === 'alle' ? 'Kein Gesprächswunsch, aber die Klasse führt alle Gespräche gemeinsam.' : 'Kein Gesprächswunsch für dieses Kind → kein gemeinsames Gespräch, die Lehrkräfte stehen getrennt.');

        $kindTermine = $team['plaene']->flatMap(fn (Plan $p) => $p->termine->where('kind_user_id', $kindId));
        $familie = Buchung::familienTermine(Buchung::kinder($eltern, $sprechtag)->pluck('kind.id')->all(), $sprechtag)
            ->load('kind')
            ->reject(fn (Termin $t) => $kindTermine->contains('id', $t->id));

        $aus['lehrer'] = $team['plaene']->map(fn (Plan $p) => Namen::anzeige($p->user->name).' ('.$p->slot_minuten.' + '.$p->vorbereitung_minuten.' Min.)')->values()->all();

        // Alle Uhrzeiten aller beteiligten Pläne – je Plan: frei, gebucht, geblockt oder gar nicht vorhanden.
        $je = $team['plaene']->map(function (Plan $p) {
            $liste = [];
            foreach ($p->tage as $pt) {
                if ($pt->tag === null) {
                    continue;
                }
                foreach (Slots::fuer($p, $pt) as $s) {
                    $liste[$pt->tag_id.'|'.$s['von']] = $s + ['tag_id' => (int) $pt->tag_id, 'tag' => $pt->tag->beschriftung(), 'datum' => $pt->tag->datum];
                }
            }

            return $liste;
        })->values();

        $alle = $je->flatMap(fn ($l) => $l)->sortBy(fn ($s) => $s['datum']->format('Y-m-d').' '.$s['von'])->unique(fn ($s) => $s['tag_id'].'|'.$s['von']);
        foreach ($alle as $key => $s) {
            $spalten = [];
            $gemeinsam = true;
            foreach ($team['plaene']->values() as $i => $p) {
                $eigen = $je[$i][$s['tag_id'].'|'.$s['von']] ?? null;
                if ($eigen === null) {
                    $spalten[] = '– keine Zeit um '.$s['von'];
                    $gemeinsam = false;
                } elseif ($eigen['bis'] !== $s['bis']) {
                    $spalten[] = 'andere Länge ('.$eigen['von'].'–'.$eigen['bis'].')';
                    $gemeinsam = false;
                } elseif ($t = $p->termine->first(fn ($t) => (int) $t->tag_id === $s['tag_id'] && $t->von === $s['von'])) {
                    $spalten[] = (int) $t->kind_user_id === $kindId ? 'gebucht (dieses Kind)' : 'vergeben';
                    $gemeinsam = $gemeinsam && (int) $t->kind_user_id === $kindId;
                } elseif ($p->geblockt($s['tag_id'], $s['von'])) {
                    $spalten[] = 'geblockt';
                } else {
                    $spalten[] = 'frei';
                }
            }

            $konflikt = $familie->first(fn (Termin $t) => (int) $t->tag_id === $s['tag_id']
                && Slots::minuten($s['von']) < Slots::minuten($t->bis) && Slots::minuten($s['bis']) > Slots::minuten($t->von));
            if ($konflikt) {
                $gemeinsam = false;
            }
            if ($s['datum']->copy()->setTimeFromTimeString($s['von'])->isPast()) {
                $gemeinsam = false;
            }

            $aus['zeilen'][] = [
                'tag' => $s['tag'],
                'zeit' => $s['von'].'–'.$s['bis'],
                'je' => $spalten,
                'familie' => $konflikt ? 'überschneidet sich mit '.Namen::anzeige($konflikt->kind?->name).' '.$konflikt->von.'–'.$konflikt->bis : 'frei',
                'gemeinsam' => $gemeinsam,
            ];
        }

        return $aus;
    }
}
