<?php

namespace Intranet\Modules\Elternsprechtag\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Intranet\Modules\Elternsprechtag\Support\Klassen;
use Intranet\Modules\Elternsprechtag\Support\Slots;

/** Terminplan eines Lehrers für einen Sprechtag. */
class Plan extends Model
{
    protected $table = 'elternsprechtag_plaene';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['abgeschlossen_am' => 'datetime', 'abgesagt_am' => 'datetime', 'klassen' => 'array'];
    }

    /** Der Lehrer nimmt an diesem Sprechtag nicht teil. */
    public function abgesagt(): bool
    {
        return $this->abgesagt_am !== null;
    }

    /**
     * Klassen, für die der Lehrer an diesem Sprechtag da ist: seine eigene Wahl,
     * sonst die Vorauswahl aus Linear – immer nur Klassen des Sprechtags.
     *
     * @return list<string>
     */
    public static function klassenVon(?self $plan, User $lehrer, Sprechtag $sprechtag): array
    {
        $wahl = $plan?->klassen ?? Klassen::vonLehrer($lehrer);
        $klassen = array_values(array_intersect($sprechtag->klassenListe(), $wahl));
        natcasesort($klassen);

        return array_values($klassen);
    }

    /** Veröffentlicht = für Eltern buchbar (Spalte `abgeschlossen_am`). */
    public function veroeffentlicht(): bool
    {
        return $this->abgeschlossen_am !== null && ! $this->abgesagt();
    }

    public function sprechtag(): BelongsTo
    {
        return $this->belongsTo(Sprechtag::class, 'sprechtag_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tage(): HasMany
    {
        return $this->hasMany(PlanTag::class, 'plan_id');
    }

    public function wuensche(): HasMany
    {
        return $this->hasMany(Wunsch::class, 'plan_id');
    }

    public function termine(): HasMany
    {
        return $this->hasMany(Termin::class, 'plan_id');
    }

    /** Anzahl Termine über alle Tage. */
    public function anzahlTermine(): int
    {
        return $this->tage->sum(fn (PlanTag $t) => count(Slots::fuer($this, $t)));
    }
}
