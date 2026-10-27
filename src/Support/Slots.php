<?php

namespace Intranet\Modules\Elternsprechtag\Support;

use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\PlanTag;

/**
 * Rechnet aus Start, Ende, Pausen, Gesprächslänge und Vorbereitung die
 * Terminslots eines Tages.
 *
 * Das erste Gespräch beginnt zur Startzeit, zwischen zwei Gesprächen liegt die
 * Vorbereitungszeit. Ein Gespräch, das in eine Pause ragen würde, rückt hinter
 * die Pause; eines, das über das Ende hinausginge, entfällt.
 */
class Slots
{
    /** @return list<array{von: string, bis: string}> */
    public static function fuer(Plan $plan, PlanTag $tag): array
    {
        return self::berechnen($tag->von, $tag->bis, (int) $plan->slot_minuten, (int) $plan->vorbereitung_minuten, $tag->pausenListe());
    }

    /**
     * @param  list<array{von: string, bis: string}>  $pausen
     * @return list<array{von: string, bis: string}>
     */
    public static function berechnen(string $von, string $bis, int $dauer, int $vorbereitung, array $pausen): array
    {
        $start = self::minuten($von);
        $ende = self::minuten($bis);

        if ($dauer < 1 || $start === null || $ende === null || $ende <= $start) {
            return [];
        }

        $sperren = [];
        foreach ($pausen as $p) {
            $a = self::minuten($p['von'] ?? '');
            $b = self::minuten($p['bis'] ?? '');
            if ($a !== null && $b !== null && $b > $a) {
                $sperren[] = [$a, $b];
            }
        }
        usort($sperren, fn ($x, $y) => $x[0] <=> $y[0]);

        $slots = [];
        $t = $start;
        while ($t + $dauer <= $ende) {
            foreach ($sperren as [$a, $b]) {
                if ($t < $b && $t + $dauer > $a) {
                    $t = $b;

                    continue 2;
                }
            }
            $slots[] = ['von' => self::uhrzeit($t), 'bis' => self::uhrzeit($t + $dauer)];
            $t += $dauer + max(0, $vorbereitung);
        }

        return $slots;
    }

    public static function minuten(?string $uhrzeit): ?int
    {
        if (! is_string($uhrzeit) || ! preg_match('/^(\d{1,2}):(\d{2})$/', $uhrzeit, $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    public static function uhrzeit(int $minuten): string
    {
        return sprintf('%02d:%02d', intdiv($minuten, 60), $minuten % 60);
    }
}
