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
