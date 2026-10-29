<?php

namespace Intranet\Modules\Elternsprechtag\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Models\Wunsch;

/**
 * Werte der Info-Mail an ein Elternteil – fürs Verschicken und für die
 * Vorschau dieselben. Eingeladene Eltern (Gesprächswunsch einer Lehrkraft für
 * eines ihrer Kinder) bekommen im Platzhalter `einladung` die Liste dazu.
 */
class InfoMail
{
    /**
     * Gesprächswünsche des Sprechtags je Elternteil. Pläne mit abgesagter
     * Teilnahme zählen nicht; die Notiz des Lehrers bleibt intern.
     *
     * @return Collection<int, list<array{lehrer: User, kind: User}>>  Schlüssel = Eltern-ID
     */
    public static function einladungen(Sprechtag $sprechtag): Collection
    {
        $je = [];
        $wuensche = Wunsch::with(['plan.user', 'kind.parents'])
            ->whereHas('plan', fn ($q) => $q->where('sprechtag_id', $sprechtag->id)->whereNull('abgesagt_am'))
            ->get();

        foreach ($wuensche as $w) {
            if (! $w->kind || ! $w->plan?->user) {
                continue;
            }
            foreach ($w->kind->parents as $eltern) {
                $je[$eltern->id][] = ['lehrer' => $w->plan->user, 'kind' => $w->kind];
            }
        }

        return collect($je)->map(fn (array $liste) => collect($liste)
            ->sortBy(fn ($z) => Namen::schluessel($z['kind']->name).' '.Namen::schluessel($z['lehrer']->name))
            ->values()->all());
    }

    /**
     * HTML- und Textwerte für VorlagenMailer.
     *
     * @param  list<array{lehrer: User, kind: User}>  $einladung
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function werte(Sprechtag $sprechtag, User $eltern, array $einladung): array
    {
        $text = [
            'name' => $eltern->name,
            'sprechtag' => $sprechtag->titel,
            'tage' => $sprechtag->tageText(),
            'klassen' => implode(', ', $sprechtag->klassenListe()),
            'link' => route('module.elternsprechtag.eltern.index'),
            'hinweis' => (string) $sprechtag->hinweis,
            'einladung' => '',
        ];
        $html = array_map('e', $text);
        $html['hinweis'] = nl2br(e((string) $sprechtag->hinweis));
        $html['einladung'] = '';

        if ($einladung !== []) {
            $zeilen = array_map(fn ($z) => $z['lehrer']->name.' – für '.$z['kind']->name, $einladung);

            $html['einladung'] = '<div style="margin:0 0 16px;padding:12px 16px;background:#eef2ff;border-left:4px solid #4f46e5;border-radius:4px;">'
                .'<p style="margin:0 0 8px;"><strong>Persönliche Einladung:</strong> Folgende Lehrkräfte möchten Sie gern sprechen:</p>'
                .'<ul style="margin:0 0 8px;padding-left:20px;">'.implode('', array_map(fn ($z) => '<li>'.e($z).'</li>', $zeilen)).'</ul>'
                .'<p style="margin:0;">Bitte buchen Sie dort möglichst einen Termin.</p></div>';

            $text['einladung'] = "Persönliche Einladung – folgende Lehrkräfte möchten Sie gern sprechen:\n"
                .implode("\n", array_map(fn ($z) => '- '.$z, $zeilen))
                ."\nBitte buchen Sie dort möglichst einen Termin.\n";
        }

        return [$html, $text];
    }
}
