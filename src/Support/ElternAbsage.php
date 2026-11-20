<?php

namespace Intranet\Modules\Elternsprechtag\Support;

use App\Mail\Vorlagen\VorlagenMailer;
use App\Models\User;
use Illuminate\Support\Collection;
use Intranet\Modules\Elternsprechtag\ElternsprechtagServiceProvider;
use Intranet\Modules\Elternsprechtag\Models\Plan;
use Intranet\Modules\Elternsprechtag\Models\Sprechtag;
use Intranet\Modules\Elternsprechtag\Models\Termin;

/**
 * Eltern sagen ihre Teilnahme ab: alle Termine ihrer Kinder entfallen, und
 * jede betroffene Lehrkraft bekommt EINE Mail mit diesen Terminen und der
 * Nachricht der Eltern. Gegenstück zu {@see Ausfall} (Lehrkraft sagt ab).
 */
class ElternAbsage
{
    /**
     * Alle Termine der Kinder dieses Elternteils am Sprechtag – bei gemeinsamen
     * Gesprächen mit den Terminen aller beteiligten Lehrkräfte.
     *
     * @return Collection<int, Termin>
     */
    public static function termine(User $eltern, Sprechtag $sprechtag): Collection
    {
        $kinder = Buchung::kinder($eltern, $sprechtag)->map(fn ($z) => $z['kind']->id);
        $planIds = Plan::where('sprechtag_id', $sprechtag->id)->pluck('id');
        if ($kinder->isEmpty() || $planIds->isEmpty()) {
            return collect();
        }

        $termine = Termin::with(['tag', 'kind', 'plan.user'])
            ->whereIn('plan_id', $planIds)->whereIn('kind_user_id', $kinder)->get();
        $gruppen = $termine->pluck('gruppe')->filter()->unique();
        if ($gruppen->isNotEmpty()) {
            $termine = $termine->concat(Termin::with(['tag', 'kind', 'plan.user'])->whereIn('gruppe', $gruppen)->get())->unique('id');
        }

        return $termine->values();
    }

    /**
     * Termine je Lehrkraft – nur Lehrkräfte mit Mailadresse.
     *
     * @return Collection<int, array{lehrer: User, termine: Collection<int, Termin>}>
     */
    public static function jeLehrer(Collection $termine): Collection
    {
        return $termine
            ->filter(fn (Termin $t) => $t->plan?->user && filled($t->plan->user->email) && ! $t->plan->user->istGesperrt())
            ->groupBy(fn (Termin $t) => $t->plan->user_id)
            ->map(fn (Collection $ts) => ['lehrer' => $ts->first()->plan->user, 'termine' => $ts->values()])
            ->values();
    }

    /** @return array{0: array<string, string>, 1: array<string, string>} [HTML-Werte, Textwerte] */
    public static function werte(Sprechtag $sprechtag, User $lehrer, string $eltern, Collection $termine, string $nachricht): array
    {
        $zeilen = $termine->sortBy(fn (Termin $t) => $t->tag?->datum?->format('Y-m-d').' '.$t->von)
            ->map(fn (Termin $t) => [$t->beschriftung(), Namen::anzeige((string) $t->kind?->name)]);

        $basis = [
            'name' => $lehrer->name,
            'sprechtag' => $sprechtag->titel,
            'eltern' => $eltern,
            'link' => route('module.elternsprechtag.plan.zeigen', $sprechtag),
        ];

        $html = array_map('e', $basis) + [
            'termine' => '<ul style="margin:0 0 16px;padding-left:20px;">'
                .$zeilen->map(fn ($z) => '<li><strong>'.e($z[0]).'</strong> ('.e($z[1]).')</li>')->join('')
                .'</ul>',
            'nachricht' => $nachricht === Ausfall::MARKE ? Ausfall::MARKE : Ausfall::nachrichtHtml($nachricht),
        ];
        $text = $basis + [
            'termine' => $zeilen->map(fn ($z) => '- '.$z[0].' ('.$z[1].')')->join("\n"),
            'nachricht' => Ausfall::nachrichtText($nachricht),
        ];

        return [$html, $text];
    }

    /**
     * Vorschau der Mail an die erste betroffene Lehrkraft, mit {@see Ausfall::MARKE}
     * an der Stelle der Nachricht. null, wenn keine Lehrkraft eine Mail bekäme.
     *
     * @return array{betreff: string, html: string, lehrer: int}|null
     */
    public static function vorschau(Sprechtag $sprechtag, User $eltern, Collection $termine): ?array
    {
        $jeLehrer = self::jeLehrer($termine);
        $erste = $jeLehrer->first();
        if ($erste === null) {
            return null;
        }

        try {
            [$html] = self::werte($sprechtag, $erste['lehrer'], $eltern->name, $erste['termine'], Ausfall::MARKE);
            $mail = app(VorlagenMailer::class)->rendern(ElternsprechtagServiceProvider::VORLAGE_ELTERN_ABSAGE, $html);
        } catch (\Throwable) {
            return null;
        }

        return ['betreff' => $mail['betreff'], 'html' => $mail['html'], 'lehrer' => $jeLehrer->count()];
    }
}
