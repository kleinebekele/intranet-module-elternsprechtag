<?php

namespace Intranet\Modules\Elternsprechtag\Support;

use App\Models\User;
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;

/**
 * Wer darf was. Den Seitenzugang regelt der Core (Menüpunkt ↔ Rolle); hier
 * steht, wessen Plan jemand sehen und ändern darf.
 */
class Rechte
{
    public const VERWALTUNG = 'elternsprechtag_verwaltung';

    /**
     * Wer die Einstellungen aufrufen darf, verwaltet – und darf auf den Seiten
     * für Lehrer und Eltern „handeln als".
     */
    public static function istVerwaltung(?User $user): bool
    {
        return $user !== null && (
            $user->isAdmin()
            || app(\App\Modules\Support\Modulzugriff::class)->darfRoute('module.elternsprechtag.sprechtage.index', $user)
        );
    }

    /**
     * Darf der Benutzer an diesem Sprechtag einen Terminplan haben? Jeder Lehrer
     * (welche Klassen er dort übernimmt, wählt er selbst) – und wer schon einen hat.
     */
    public static function istLehrerIn(User $user, Sprechtag $sprechtag): bool
    {
        return self::istLehrer($user)
            || Plan::where(['sprechtag_id' => $sprechtag->id, 'user_id' => $user->id])->exists();
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
