<?php

namespace Intranet\Modules\Elternsprechtag\Support;

/**
 * Termin-Assistent für Eltern: aus den gewünschten Gesprächen (Prio 1–3) und
 * deren freien Uhrzeiten eine Abfolge ohne Überschneidung bauen.
 *
 * Bewertet wird in dieser Reihenfolge:
 *  1. möglichst viele Gespräche mit Prio 1, dann Prio 2, dann Prio 3,
 *  2. möglichst wenige Tage (jeder Tag ist ein eigener Weg zur Schule),
 *  3. möglichst wenig Wartezeit (Zeit vom ersten Beginn bis zum letzten Ende je Tag),
 *  4. möglichst wenige Lehrerwechsel – zwei Gespräche bei derselben Lehrkraft
 *     (Geschwister) liegen direkt hintereinander statt mit einem Umweg dazwischen,
 *  5. möglichst früh.
 *
 * Gesucht wird vollständig (Tiefensuche mit Schranken); bei sehr vielen
 * Möglichkeiten endet die Suche nach einer festen Zahl Schritte mit dem besten
 * bis dahin gefundenen Vorschlag.
 */
class Assistent
{
    private const MAX_SCHRITTE = 300000;

    /** @var list<array{key: string, prio: int, slots: list<array{tag_id: int, tag: string, von: string, bis: string, a: int, e: int}>}> */
    private array $wuensche = [];

    /** @var array<int, array<int, int>> ab Index i: noch mögliche Anzahl je Prio */
    private array $rest = [];

    /** @var array<int, int> ab Index i: Mindestdauer der noch möglichen Gespräche */
    private array $dauerRest = [];

    /** @var array<int, array<string, true>> ab Index i: Lehrkräfte der noch möglichen Gespräche */
    private array $lehrerRest = [];

    private int $abstand = 0;

    private int $schritte = 0;

    private ?array $bestes = null;

    /**
     * @param  list<array{key: string, prio: int, lehrer?: int|string, slots: list<array{tag_id: int, tag: string, von: string, bis: string}>}>  $wuensche
     * @param  int  $abstand  Mindestabstand zwischen zwei Gesprächen in Minuten (Wege zwischen Räumen)
     * @return array{plan: list<array{key: string, prio: int, slot: array}>, offen: list<string>}
     */
    public static function planen(array $wuensche, int $abstand = 0): array
    {
        $a = new self;
        $a->abstand = max(0, $abstand);

        foreach ($wuensche as $w) {
            $lehrer = (string) ($w['lehrer'] ?? $w['key']);
            $slots = array_map(fn ($s) => $s + ['a' => Slots::minuten($s['von']), 'e' => Slots::minuten($s['bis']), 'l' => $lehrer], $w['slots']);
            usort($slots, fn ($x, $y) => [$x['tag_id'], $x['a']] <=> [$y['tag_id'], $y['a']]);
            $a->wuensche[] = ['key' => $w['key'], 'prio' => $w['prio'], 'lehrer' => $lehrer, 'slots' => $slots];
        }

        // Wichtige zuerst, bei gleicher Prio die mit den wenigsten Möglichkeiten.
        usort($a->wuensche, fn ($x, $y) => [$x['prio'], count($x['slots'])] <=> [$y['prio'], count($y['slots'])]);

        $n = count($a->wuensche);
        $a->rest[$n] = [1 => 0, 2 => 0, 3 => 0];
        $a->dauerRest[$n] = 0;
        $a->lehrerRest[$n] = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $a->rest[$i] = $a->rest[$i + 1];
            $a->dauerRest[$i] = $a->dauerRest[$i + 1];
            $a->lehrerRest[$i] = $a->lehrerRest[$i + 1];
            if ($a->wuensche[$i]['slots'] !== []) {
                $a->rest[$i][$a->wuensche[$i]['prio']]++;
                $a->lehrerRest[$i][$a->wuensche[$i]['lehrer']] = true;
                $a->dauerRest[$i] += min(array_map(fn ($s) => $s['e'] - $s['a'], $a->wuensche[$i]['slots']));
            }
        }

        $a->suchen(0, [], [1 => 0, 2 => 0, 3 => 0]);

        $gewaehlt = $a->bestes['gewaehlt'] ?? [];
        $plan = [];
        foreach ($gewaehlt as $i => $s) {
            $plan[] = ['key' => $a->wuensche[$i]['key'], 'prio' => $a->wuensche[$i]['prio'], 'slot' => array_diff_key($s, ['a' => 0, 'e' => 0, 'l' => 0])];
        }
        usort($plan, fn ($x, $y) => [$x['slot']['tag_id'], $x['slot']['von']] <=> [$y['slot']['tag_id'], $y['slot']['von']]);

        $offen = [];
        foreach ($a->wuensche as $i => $w) {
            if (! isset($gewaehlt[$i])) {
                $offen[] = $w['key'];
            }
        }

        return ['plan' => $plan, 'offen' => $offen];
    }

    /** @param array<int, array> $gewaehlt  Index → Slot */
    private function suchen(int $i, array $gewaehlt, array $anzahl): void
    {
        if (++$this->schritte > self::MAX_SCHRITTE) {
            return;
        }

        // Schranke: selbst wenn alles Übrige noch klappt – reicht es, das Beste zu schlagen?
        if ($this->bestes !== null) {
            $moeglich = [1 => $anzahl[1] + $this->rest[$i][1], 2 => $anzahl[2] + $this->rest[$i][2], 3 => $anzahl[3] + $this->rest[$i][3]];
            $vergleich = $this->vergleichAnzahl($moeglich, $this->bestes['anzahl']);
            if ($vergleich < 0) {
                return;
            }
            // Gleichstand ist nur noch erreichbar, wenn alles Übrige gebucht wird – und
            // jedes weitere Gespräch macht Tage, Spanne, Wechsel und Frühe höchstens größer.
            if ($vergleich === 0) {
                // Untergrenze: die Spanne ist mindestens die Summe aller Gesprächsdauern.
                $k = $this->kosten($gewaehlt);
                $dauer = $this->dauerRest[$i] + array_sum(array_map(fn ($g) => $g['e'] - $g['a'], $gewaehlt));
                $k[1] = max($k[1], $dauer);
                $k[0] = max($k[0], 1);
                // Je Tag mindestens (Lehrkräfte − 1) Wechsel – über alle Tage also Lehrkräfte − Tage.
                $lehrer = $this->lehrerRest[$i];
                foreach ($gewaehlt as $g) {
                    $lehrer[$g['l']] = true;
                }
                $k[2] = max($k[2], count($lehrer) - $k[0]);
                if ([$k[0], $k[1], $k[2]] > [$this->bestes['kosten'][0], $this->bestes['kosten'][1], $this->bestes['kosten'][2]] || $k >= $this->bestes['kosten']) {
                    return;
                }
            }
        }

        if ($i === count($this->wuensche)) {
            $this->merken($gewaehlt, $anzahl);

            return;
        }

        $prio = $this->wuensche[$i]['prio'];
        foreach ($this->naechstgelegen($this->wuensche[$i]['slots'], $gewaehlt) as $s) {
            if ($this->passt($s, $gewaehlt)) {
                $neu = $gewaehlt;
                $neu[$i] = $s;
                $mehr = $anzahl;
                $mehr[$prio]++;
                $this->suchen($i + 1, $neu, $mehr);
            }
        }

        // Auch ohne dieses Gespräch weitersuchen.
        $this->suchen($i + 1, $gewaehlt, $anzahl);
    }

    /** Slots so ordnen, dass die zu schon gewählten Gesprächen nächstgelegenen zuerst kommen – findet früh kompakte Lösungen. */
    private function naechstgelegen(array $slots, array $gewaehlt): array
    {
        if ($gewaehlt === []) {
            return $slots;
        }
        $abstandZu = function (array $s) use ($gewaehlt): int {
            $min = PHP_INT_MAX;
            foreach ($gewaehlt as $g) {
                $d = $g['tag_id'] === $s['tag_id'] ? min(abs($s['a'] - $g['e']), abs($g['a'] - $s['e'])) * 2 + ($g['l'] === $s['l'] ? 0 : 1) : 100000;
                $min = min($min, $d);
            }

            return $min;
        };
        usort($slots, fn ($x, $y) => [$abstandZu($x), $x['a']] <=> [$abstandZu($y), $y['a']]);

        return $slots;
    }

    private function passt(array $s, array $gewaehlt): bool
    {
        foreach ($gewaehlt as $g) {
            // Bei derselben Lehrkraft bleibt man im Raum – kein Abstand nötig.
            $abstand = $g['l'] === $s['l'] ? 0 : $this->abstand;
            if ($g['tag_id'] === $s['tag_id'] && $s['a'] < $g['e'] + $abstand && $s['e'] + $abstand > $g['a']) {
                return false;
            }
        }

        return true;
    }

    private function merken(array $gewaehlt, array $anzahl): void
    {
        $kosten = $this->kosten($gewaehlt);
        if ($this->bestes === null
            || ($v = $this->vergleichAnzahl($anzahl, $this->bestes['anzahl'])) > 0
            || ($v === 0 && $kosten < $this->bestes['kosten'])) {
            $this->bestes = ['gewaehlt' => $gewaehlt, 'anzahl' => $anzahl, 'kosten' => $kosten];
        }
    }

    /**
     * Kosten als Tupel [Tage, Spanne in Minuten, Lehrerwechsel, Frühe] – verglichen
     * wird von vorn nach hinten. Ein weiteres Gespräch macht keinen Wert kleiner;
     * darauf beruht die Schranke in suchen().
     */
    private function kosten(array $gewaehlt): array
    {
        $tage = [];
        $folge = [];
        foreach ($gewaehlt as $g) {
            $t = $g['tag_id'];
            $tage[$t] = isset($tage[$t]) ? [min($tage[$t][0], $g['a']), max($tage[$t][1], $g['e'])] : [$g['a'], $g['e']];
            $folge[$t][] = $g;
        }
        $spanne = array_sum(array_map(fn ($t) => $t[1] - $t[0], $tage));
        $frueh = $tage === [] ? 0 : min(array_map(fn ($id, $t) => $id * 10000 + $t[0], array_keys($tage), $tage));

        $wechsel = 0;
        foreach ($folge as $liste) {
            usort($liste, fn ($x, $y) => $x['a'] <=> $y['a']);
            for ($k = 1; $k < count($liste); $k++) {
                $wechsel += $liste[$k]['l'] === $liste[$k - 1]['l'] ? 0 : 1;
            }
        }

        return [count($tage), $spanne, $wechsel, $frueh];
    }

    private function vergleichAnzahl(array $x, array $y): int
    {
        return [$x[1], $x[2], $x[3]] <=> [$y[1], $y[2], $y[3]];
    }
}
