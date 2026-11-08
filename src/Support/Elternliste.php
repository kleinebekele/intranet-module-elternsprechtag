<?php

namespace Intranet\Modules\Elternsprechtag\Support;

use App\Mail\Vorlagen\VorlagenMailer;
use App\Models\User;
use Illuminate\Support\Collection;
use Intranet\Modules\Elternsprechtag\ElternsprechtagServiceProvider;
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;

/**
 * Terminliste für Eltern: alle gebuchten Gespräche der Familie an einem
 * Sprechtag – als PDF zum Ansehen/Herunterladen und per Mail.
 */
class Elternliste
{
    public const QUELLE = 'Elternsprechtag-Elternliste';

    /**
     * @return Collection<int, array{tag: string, datum: string, von: string, bis: string, kind: string, klasse: string, lehrer: string, raum: string}>
     */
    public static function eintraege(User $eltern, Sprechtag $sprechtag): Collection
    {
        return Buchung::angebot($eltern, $sprechtag)
            ->flatMap(fn (array $z) => $z['lehrer']->filter(fn ($l) => $l['termin'])->map(function ($l) use ($z) {
                $t = $l['termin'];
                $t->loadMissing('tag');
                // Gemeinsam gedacht, aber nur bei einer Lehrkraft gebucht → nur deren Name und Raum.
                $plan = $l['team'] && ! $t->gruppe ? $l['team']->firstWhere('id', $t->plan_id) : $l['plan'];
                $lehrer = $l['team'] && $t->gruppe ? $l['namen'] : Namen::anzeige($plan?->user?->name);

                return [
                    'tag' => (string) $t->tag?->beschriftung(),
                    'datum' => (string) $t->tag?->datum?->format('Y-m-d'),
                    'von' => $t->von,
                    'bis' => $t->bis,
                    'kind' => Namen::vorname($z['kind']->name),
                    'klasse' => implode(', ', $z['klassen']),
                    'lehrer' => $lehrer,
                    'raum' => (string) ($plan instanceof Plan ? $plan->raum : ''),
                ];
            }))
            ->sortBy(fn ($e) => $e['datum'].' '.$e['von'])
            ->values();
    }

    /** PDF-Inhalt (Bytes). */
    public static function pdf(User $eltern, Sprechtag $sprechtag): string
    {
        return \Barryvdh\DomPDF\Facade\Pdf::setOption('isFontSubsettingEnabled', true)
            ->loadView('elternsprechtag::eltern.pdf', self::daten($eltern, $sprechtag))
            ->output();
    }

    public static function daten(User $eltern, Sprechtag $sprechtag): array
    {
        return ['eltern' => $eltern, 'sprechtag' => $sprechtag, 'eintraege' => self::eintraege($eltern, $sprechtag)];
    }

    public static function dateiname(Sprechtag $sprechtag): string
    {
        return 'Elternsprechtag-Termine-'.str($sprechtag->titel)->slug().'.pdf';
    }

    /** Liste per Mail an das Elternteil (über den Ausgangskorb). */
    public static function senden(User $eltern, Sprechtag $sprechtag): bool
    {
        if (blank($eltern->email) || $eltern->istGesperrt()) {
            return false;
        }

        $eintraege = self::eintraege($eltern, $sprechtag);
        $ordner = storage_path('app/elternsprechtag/elternlisten');
        if (! is_dir($ordner)) {
            mkdir($ordner, 0775, true);
        }
        $pfad = $ordner.'/termine-'.$sprechtag->id.'-'.$eltern->id.'.pdf';
        file_put_contents($pfad, self::pdf($eltern, $sprechtag));

        $text = $eintraege->map(fn ($e) => $e['tag'].', '.$e['von'].'–'.$e['bis'].' Uhr: '.$e['lehrer'].' für '.$e['kind'].($e['raum'] !== '' ? ' (Raum '.$e['raum'].')' : ''))->join("\n");
        $html = '<ul style="margin:0;padding-left:20px;">'.$eintraege->map(fn ($e) => '<li><strong>'.e($e['tag'].', '.$e['von'].'–'.$e['bis'].' Uhr')
            .'</strong>: '.e($e['lehrer']).' für '.e($e['kind']).($e['raum'] !== '' ? ' (Raum '.e($e['raum']).')' : '').'</li>')->join('').'</ul>';

        $werte = [
            'name' => $eltern->name,
            'sprechtag' => $sprechtag->titel,
            'tage' => $sprechtag->tageText(),
            'termine' => $text,
            'anzahl' => (string) $eintraege->count(),
            'link' => route('module.elternsprechtag.eltern.index'),
        ];
        $htmlWerte = array_map('e', $werte);
        $htmlWerte['termine'] = $eintraege->isEmpty() ? '<p>Noch keine Termine gebucht.</p>'
            : '<div style="margin:0 0 16px;padding:12px 16px;background:#eef2ff;border-left:4px solid #4f46e5;border-radius:4px;">'.$html.'</div>';

        app(VorlagenMailer::class)->senden(
            ElternsprechtagServiceProvider::VORLAGE_ELTERNLISTE, $eltern->email, $htmlWerte, $werte,
            quelle: self::QUELLE, referenz: 'elternsprechtag-elternliste:'.$sprechtag->id.':'.$eltern->id, modul: 'Elternsprechtag',
            anhaenge: [['pfad' => $pfad, 'name' => self::dateiname($sprechtag)]],
        );

        return true;
    }
}
