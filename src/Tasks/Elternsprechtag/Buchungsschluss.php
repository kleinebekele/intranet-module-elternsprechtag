<?php

namespace Intranet\Modules\Elternsprechtag\Tasks\Elternsprechtag;

use App\Ekkon\Tasks\EkkonTask;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Support\Lehrerinfo;

/**
 * Buchungsschluss am Vorabend des ersten Tages, 18 Uhr: Eltern können ab dann
 * nichts mehr buchen oder absagen (Sprechtag::buchungGeschlossen()), und alle
 * teilnehmenden Lehrkräfte, die ihre Liste nicht schon wegen eines vollen
 * Plans bekommen haben, erhalten jetzt ihre Terminliste als PDF.
 */
class Buchungsschluss extends EkkonTask
{
    public string $category = 'Elternsprechtag';

    public string $description = 'Am Vorabend des Elternsprechtags um 18 Uhr: Buchung für Eltern schließen und allen übrigen Lehrkräften ihre Terminliste als PDF schicken. Läuft alle 5 Minuten und holt einen verpassten Zeitpunkt nach.';

    public function schedule(): string
    {
        return '*/5 * * * *';
    }

    public function run(): array
    {
        $ergebnis = ['sprechtage' => 0, 'mails' => 0];

        $offen = Sprechtag::with('tage')->whereNull('schluss_gemeldet_am')->whereNotNull('info_versendet_am')->get()
            ->filter(fn (Sprechtag $s) => $s->buchungGeschlossen() && ! $s->vorbei());

        foreach ($offen as $sprechtag) {
            $n = Lehrerinfo::schliessen($sprechtag);
            $this->msg($sprechtag->titel.': Buchung geschlossen, '.$n.' Terminliste(n) verschickt.');
            $ergebnis['sprechtage']++;
            $ergebnis['mails'] += $n;
        }

        return $ergebnis;
    }
}
