<?php

namespace Intranet\Modules\Elternsprechtag\Support;

use App\Mail\Vorlagen\VorlagenMailer;
use Illuminate\Support\Facades\Log;
use Intranet\Modules\Elternsprechtag\ElternsprechtagServiceProvider;
use Intranet\Modules\Elternsprechtag\Http\Controllers\PlanController;
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\PlanTag;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Throwable;

/**
 * Terminliste als PDF per Mail an die Lehrkraft.
 *
 * - Plan voll (jeder Slot gebucht) → Liste geht raus, `voll_gemeldet_am` gesetzt.
 *   Wird wieder ein Termin frei, fällt die Marke weg; beim nächsten Vollwerden
 *   geht die Liste erneut raus.
 * - Buchungsschluss (Vorabend 18 Uhr, Task Elternsprechtag/Buchungsschluss) →
 *   alle übrigen teilnehmenden Lehrkräfte bekommen ihre Liste.
 * - Danach ändert nur noch die Verwaltung etwas; jede solche Änderung schickt
 *   der Lehrkraft die aktuelle Liste (eigene Absagen der Lehrkraft nicht).
 */
class Lehrerinfo
{
    public const QUELLE = 'Elternsprechtag-Terminliste';

    /** Ist jeder Slot des Plans gebucht? Ein Plan ohne Slots ist nie voll. */
    public static function voll(Plan $plan): bool
    {
        $plan->loadMissing(['tage.tag', 'termine']);
        $slots = 0;
        $belegt = 0;
        foreach ($plan->tage as $pt) {
            /** @var PlanTag $pt */
            if ($pt->tag === null) {
                continue;
            }
            $vergeben = $plan->termine->where('tag_id', $pt->tag_id)->pluck('von')->all();
            foreach (Slots::fuer($plan, $pt) as $s) {
                $slots++;
                $belegt += in_array($s['von'], $vergeben, true) ? 1 : 0;
            }
        }

        return $slots > 0 && $belegt === $slots;
    }

    /**
     * Nach jeder Buchung bzw. Absage für die betroffenen Pläne aufrufen.
     *
     * @param  iterable<int|string>  $planIds
     */
    public static function pruefen(iterable $planIds): void
    {
        $ids = collect($planIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $plaene = Plan::with(['tage.tag', 'termine', 'user', 'sprechtag.tage'])->whereIn('id', $ids)->get();
        foreach ($plaene as $plan) {
            if ($plan->abgesagt() || $plan->user === null || $plan->sprechtag === null) {
                continue;
            }

            if ($plan->sprechtag->schluss_gemeldet_am !== null) {
                if (auth()->id() !== (int) $plan->user_id) {
                    self::senden($plan, 'geaendert');
                }

                continue;
            }

            $voll = self::voll($plan);
            if ($voll && $plan->voll_gemeldet_am === null) {
                if (self::senden($plan, 'voll')) {
                    $plan->forceFill(['voll_gemeldet_am' => now()])->save();
                }
            } elseif (! $voll && $plan->voll_gemeldet_am !== null) {
                $plan->forceFill(['voll_gemeldet_am' => null])->save();
            }
        }
    }

    /**
     * Buchungsschluss: Liste an alle teilnehmenden Lehrkräfte, die noch keine
     * Voll-Meldung haben. Gibt die Zahl der verschickten Mails zurück.
     */
    public static function schliessen(Sprechtag $sprechtag): int
    {
        $gesendet = 0;
        $plaene = Plan::with(['tage.tag', 'termine', 'user', 'sprechtag.tage'])->where('sprechtag_id', $sprechtag->id)->get();
        foreach ($plaene as $plan) {
            if ($plan->abgesagt() || $plan->user === null || $plan->voll_gemeldet_am !== null || $plan->anzahlTermine() === 0) {
                continue;
            }
            $gesendet += self::senden($plan, 'schluss') ? 1 : 0;
        }
        $sprechtag->forceFill(['schluss_gemeldet_am' => now()])->save();

        return $gesendet;
    }

    /** Mail mit PDF an die Lehrkraft. false = keine Adresse, gesperrt oder Fehler. */
    public static function senden(Plan $plan, string $anlass): bool
    {
        $lehrer = $plan->user;
        $sprechtag = $plan->sprechtag;
        if ($lehrer === null || $lehrer->istGesperrt() || blank($lehrer->email)) {
            return false;
        }

        try {
            $pfad = self::pdf($plan);
            $gebucht = $plan->termine->count();
            $gesamt = $plan->anzahlTermine();
            $werte = [
                'name' => $lehrer->name,
                'sprechtag' => $sprechtag->titel,
                'tage' => $sprechtag->tageText(),
                'anlass' => match ($anlass) {
                    'voll' => 'dein Terminplan ist voll – alle Termine sind gebucht. Sagt jemand ab, bekommst du die Liste erneut, sobald wieder alles belegt ist.',
                    'schluss' => 'die Terminbuchung ist geschlossen; Eltern können jetzt nichts mehr buchen oder absagen.',
                    default => 'an deinem Terminplan hat sich nach dem Buchungsschluss etwas geändert.',
                },
                'stand' => $gebucht.' von '.$gesamt.' Terminen gebucht',
                'link' => route('module.elternsprechtag.plan.index'),
            ];
            app(VorlagenMailer::class)->senden(
                ElternsprechtagServiceProvider::VORLAGE_TERMINLISTE, $lehrer->email, array_map('e', $werte), $werte,
                quelle: self::QUELLE, referenz: 'elternsprechtag-terminliste:'.$sprechtag->id.':'.$plan->id, modul: 'Elternsprechtag',
                anhaenge: [['pfad' => $pfad, 'name' => 'Terminliste-'.str($lehrer->name)->slug().'.pdf']],
            );

            return true;
        } catch (Throwable $e) {
            Log::warning('Elternsprechtag-Terminliste nicht verschickt.', ['plan' => $plan->id, 'fehler' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * PDF ablegen. Fester Name je Plan: der Ausgangskorb liest die Datei erst
     * beim Versand – dann ist es ohnehin der neueste Stand.
     */
    private static function pdf(Plan $plan): string
    {
        $ordner = storage_path('app/elternsprechtag/terminlisten');
        if (! is_dir($ordner)) {
            mkdir($ordner, 0775, true);
        }
        $pfad = $ordner.'/terminliste-'.$plan->id.'.pdf';
        $daten = app(PlanController::class)->terminliste($plan->user, $plan->sprechtag);

        \Barryvdh\DomPDF\Facade\Pdf::setOption('isFontSubsettingEnabled', true)
            ->loadView('elternsprechtag::plan.pdf', $daten)
            ->save($pfad);

        return $pfad;
    }
}
