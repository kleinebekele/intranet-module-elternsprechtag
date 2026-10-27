<?php

namespace Intranet\Modules\Elternsprechtag\Support;

use App\Models\User;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;

/**
 * Wer darf was. Den Seitenzugang regelt der Core (Menüpunkt ↔ Rolle); hier
 * steht, wessen Plan jemand sehen und ändern darf.
 */
class Rechte
{
    public const VERWALTUNG = 'elternsprechtag_verwaltung';

    public static function istVerwaltung(?User $user): bool
    {
        return $user !== null && ($user->isAdmin() || $user->hatRolle(self::VERWALTUNG));
    }

    /** Unterrichtet der Benutzer in mindestens einer Klasse des Sprechtags? */
    public static function istLehrerIn(User $user, Sprechtag $sprechtag): bool
    {
        return array_intersect(Klassen::vonLehrer($user), $sprechtag->klassenListe()) !== [];
    }

    /** Lehrer selbst bis zum Ende des Fristtags, die Verwaltung immer. */
    public static function darfPlanAendern(User $user, Sprechtag $sprechtag, User $lehrer): bool
    {
        if (self::istVerwaltung($user)) {
            return true;
        }

        return $user->is($lehrer) && ! $sprechtag->fristVorbei();
    }

    /** Für den Menüpunkt „Mein Terminplan": irgendeine Linear-Lehrerrolle. */
    public static function istLehrer(?User $user): bool
    {
        return $user !== null && Klassen::vonLehrer($user) !== [];
    }
}
