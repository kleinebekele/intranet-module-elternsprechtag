<?php

namespace Intranet\Modules\Elternsprechtag\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Intranet\Modules\Elternsprechtag\Support\Slots;

/** Terminplan eines Lehrers für einen Sprechtag. */
class Plan extends Model
{
    protected $table = 'elternsprechtag_plaene';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['abgeschlossen_am' => 'datetime'];
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

    /** Anzahl Termine über alle Tage. */
    public function anzahlTermine(): int
    {
        return $this->tage->sum(fn (PlanTag $t) => count(Slots::fuer($this, $t)));
    }
}
