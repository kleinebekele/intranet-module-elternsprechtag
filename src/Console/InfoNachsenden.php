<?php

namespace Intranet\Modules\Elternsprechtag\Console;

use App\Mail\Vorlagen\VorlagenMailer;
use Illuminate\Console\Command;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Support\InfoMail;

/**
 * Zweite Welle der Eltern-Info: Persönlich eingeladene Eltern bekommen die
 * Mail beim Veröffentlichen, alle anderen nach dem Vorsprung (Einstellung am
 * Sprechtag). Läuft jede Minute und liefert in den Ausgangskorb ein.
 */
class InfoNachsenden extends Command
{
    protected $signature = 'elternsprechtag:info-nachsenden';

    protected $description = 'Info-Mail des Elternsprechtags nach dem Vorsprung an die übrigen Eltern verschicken';

    public function handle(VorlagenMailer $mailer): int
    {
        $faellig = Sprechtag::with('tage')
            ->whereNotNull('info_versendet_am')
            ->whereNull('info_rest_versendet_am')
            ->get()
            ->filter(fn (Sprechtag $s) => $s->restFaelligAm()->isPast());

        foreach ($faellig as $s) {
            // Erst markieren, dann verschicken: ein zweiter Lauf soll nie doppelt senden.
            $s->update(['info_rest_versendet_am' => now()]);
            [$gesendet, $fehler] = InfoMail::versenden($s, $mailer, InfoMail::uebrige($s));
            $s->increment('info_empfaenger', $gesendet);
            $this->line("„{$s->titel}\": Info an {$gesendet} weitere Eltern".($fehler ? ", {$fehler} fehlgeschlagen" : '').'.');
        }

        return self::SUCCESS;
    }
}
