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
    /** Markierungen im einheitlichen Raster (Schlüssel = gespeicherter Wert). */
    public const STATUS = ['frei' => 'frei', 'pause' => 'Pause', 'gesperrt' => 'gesperrt', 'backup' => 'Reserve'];

    /**
     * Buchbare Termine eines Tages: „frei" – und „Reserve", sobald alle übrigen
     * Termine der Lehrkraft vergeben sind (s. {@see reserveOffen()}).
     *
     * @return list<array{von: string, bis: string, status: string}>
     */
    public static function fuer(Plan $plan, PlanTag $tag): array
    {
        $raster = self::raster($plan, $tag);
        $reserve = in_array('backup', array_column($raster, 'status'), true) && self::reserveOffen($plan);

        return array_values(array_filter($raster, fn (array $s) => $s['status'] === 'frei' || ($reserve && $s['status'] === 'backup')));
    }

    /**
     * Reserve öffnet, wenn die Lehrkraft an allen Tagen keinen freien Termin mehr
     * hat – jeder „frei"-Termin ist gebucht oder schon vorbei. Wird danach ein
     * Termin wieder frei, schließt die Reserve; dort Gebuchtes bleibt.
     */
    public static function reserveOffen(Plan $plan): bool
    {
        $plan->loadMissing(['tage.tag', 'termine']);
        foreach ($plan->tage as $pt) {
            if ($pt->tag === null) {
                continue;
            }
            $vergeben = $plan->termine->where('tag_id', $pt->tag_id)->pluck('von')->all();
            foreach (self::raster($plan, $pt) as $s) {
                if ($s['status'] === 'frei' && ! in_array($s['von'], $vergeben, true)
                    && $pt->tag->datum->copy()->setTimeFromTimeString($s['von'])->isFuture()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Alle Termine eines Tages mit Status. Einheitliches Raster: Zeiten des Tages
     * und Dauer des Sprechtags, keine Vorbereitung, statt Pausen-Zeiträumen die
     * Markierung je Uhrzeit. Sonst: eigene Zeiten und Pausen der Lehrkraft, alles frei.
     *
     * @return list<array{von: string, bis: string, status: string}>
     */
    public static function raster(Plan $plan, PlanTag $tag): array
    {
        $sprechtag = $plan->sprechtag;
        if ($sprechtag?->einheitlich() && $tag->tag) {
            $markiert = (array) ($tag->markierungen ?? []);
            $slots = self::berechnen((string) ($tag->tag->standard_von ?: $tag->von), (string) ($tag->tag->standard_bis ?: $tag->bis),
                (int) $sprechtag->slot_minuten, 0, []);

            return array_map(fn (array $s) => $s + ['status' => isset(self::STATUS[$markiert[$s['von']] ?? '']) ? $markiert[$s['von']] : 'frei'], $slots);
        }

        return array_map(fn (array $s) => $s + ['status' => 'frei'],
            self::berechnen($tag->von, $tag->bis, (int) $plan->slot_minuten, (int) $plan->vorbereitung_minuten, $tag->pausenListe()));
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
