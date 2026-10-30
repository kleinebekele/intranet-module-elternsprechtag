<?php

namespace Intranet\Modules\Elternsprechtag\Support;

use Illuminate\Support\Str;

/**
 * Sortierung nach Nachnamen. Namen stehen als „Vorname Nachname“ im Intranet
 * (Linear-Import); das letzte Wort ist der Nachname. „Nachname, Vorname“ wird
 * ebenfalls erkannt.
 */
class Namen
{
    /** Für Listen: „Nachname, Vorname“. Einzelne Wörter und schon umgedrehte Namen bleiben, wie sie sind. */
    public static function anzeige(?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '' || str_contains($name, ',')) {
            return $name;
        }

        $teile = preg_split('/\s+/', $name) ?: [];
        $nach = (string) array_pop($teile);

        return $teile === [] ? $nach : $nach.', '.implode(' ', $teile);
    }

    /** Nur der Vorname (alles vor dem Nachnamen) – für Kinder auf der Seite der Eltern. */
    public static function vorname(?string $name): string
    {
        $name = trim((string) $name);
        if (str_contains($name, ',')) {
            return trim(explode(',', $name, 2)[1]) ?: $name;
        }

        $teile = preg_split('/\s+/', $name) ?: [];
        array_pop($teile);

        return $teile === [] ? $name : implode(' ', $teile);
    }

    /** Schlüssel „nachname vorname“, ohne Umlaute und Groß/Klein – für sortBy(). */
    public static function schluessel(?string $name): string
    {
        $name = trim((string) $name);

        if (str_contains($name, ',')) {
            [$nach, $vor] = array_map('trim', explode(',', $name, 2));
        } else {
            $teile = preg_split('/\s+/', $name) ?: [];
            $nach = (string) array_pop($teile);
            $vor = implode(' ', $teile);
        }

        return Str::lower(Str::ascii($nach.' '.$vor));
    }
}
