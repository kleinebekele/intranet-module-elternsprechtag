<?php

namespace Intranet\Modules\Elternsprechtag\Support;

use App\Mail\Vorlagen\VorlagenMailer;
use App\Models\User;
use Illuminate\Support\Collection;
use Intranet\Modules\Elternsprechtag\ElternsprechtagServiceProvider;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Models\Termin;

/**
 * Eine Lehrkraft fällt aus (z. B. krank) und sagt ihre Teilnahme ab: Jedes
 * Elternteil mit gebuchten Terminen bekommt EINE Mail mit allen betroffenen
 * Terminen und – falls geschrieben – der persönlichen Nachricht der Lehrkraft.
 */
class Ausfall
{
    /** Platzhalter für die Live-Vorschau; das Fenster ersetzt ihn beim Tippen. */
    public const MARKE = '%%NACHRICHT%%';

    /** Die Nachricht als hervorgehobener Absatz – leer, wenn nichts geschrieben wurde. Muss zu `nachrichtHtml()` im Fenster passen. */
    public static function nachrichtHtml(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return '<div style="margin:0 0 16px;padding:12px 16px;background:#f3f4f6;border-left:4px solid #9ca3af;border-radius:4px;white-space:pre-line;">'.e($text).'</div>';
    }

    public static function nachrichtText(string $text): string
    {
        $text = trim($text);

        return $text === '' ? '' : $text."\n\n";
    }

    /**
     * Platzhalterwerte für ein Elternteil.
     *
     * @param  Collection<int, Termin>  $termine  Termine dieses Elternteils bei der Lehrkraft
     * @return array{0: array<string, string>, 1: array<string, string>}  [HTML-Werte, Textwerte]
     */
    public static function werte(Sprechtag $sprechtag, User $eltern, string $lehrer, Collection $termine, string $nachricht): array
    {
        $zeilen = $termine->sortBy(fn (Termin $t) => $t->tag?->datum?->format('Y-m-d').' '.$t->von)
            ->map(fn (Termin $t) => [$t->beschriftung(), Namen::vorname((string) $t->kind?->name)]);

        $basis = [
            'name' => $eltern->name,
            'sprechtag' => $sprechtag->titel,
            'lehrer' => $lehrer,
            'link' => route('module.elternsprechtag.eltern.index'),
        ];

        $html = array_map('e', $basis) + [
            'termine' => '<ul style="margin:0 0 16px;padding-left:20px;">'
                .$zeilen->map(fn ($z) => '<li><strong>'.e($z[0]).'</strong> (für '.e($z[1]).')</li>')->join('')
                .'</ul>',
            'nachricht' => $nachricht === self::MARKE ? self::MARKE : self::nachrichtHtml($nachricht),
        ];
        $text = $basis + [
            'termine' => $zeilen->map(fn ($z) => '- '.$z[0].' (für '.$z[1].')')->join("\n"),
            'nachricht' => self::nachrichtText($nachricht),
        ];

        return [$html, $text];
    }

    /**
     * Vorschau der Mail an das erste betroffene Elternteil – mit {@see MARKE}
     * an der Stelle der Nachricht. null, wenn niemand eine Mail bekäme.
     *
     * @param  Collection<int, Termin>  $termine  alle gebuchten Termine der Lehrkraft (mit tag, kind, eltern)
     * @return array{betreff: string, html: string, eltern: int}|null
     */
    public static function vorschau(Sprechtag $sprechtag, string $lehrer, Collection $termine): ?array
    {
        $jeEltern = self::jeEltern($termine);
        $erste = $jeEltern->first();
        if ($erste === null) {
            return null;
        }

        try {
            [$html] = self::werte($sprechtag, $erste['eltern'], $lehrer, $erste['termine'], self::MARKE);
            $mail = app(VorlagenMailer::class)->rendern(ElternsprechtagServiceProvider::VORLAGE_AUSFALL, $html);
        } catch (\Throwable) {
            return null;
        }

        return ['betreff' => $mail['betreff'], 'html' => $mail['html'], 'eltern' => $jeEltern->count()];
    }

    /**
     * Termine nach Elternteil gruppiert – nur Eltern, die eine Mail bekommen können.
     *
     * @return Collection<int, array{eltern: User, termine: Collection<int, Termin>}>
     */
    public static function jeEltern(Collection $termine): Collection
    {
        return $termine
            ->filter(fn (Termin $t) => $t->eltern && ! $t->eltern->istGesperrt() && filled($t->eltern->email))
            ->groupBy('eltern_user_id')
            ->map(fn (Collection $ts) => ['eltern' => $ts->first()->eltern, 'termine' => $ts->values()])
            ->values();
    }
}
