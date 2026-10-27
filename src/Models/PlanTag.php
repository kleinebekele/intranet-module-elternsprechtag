<?php

namespace Intranet\Modules\Elternsprechtag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ein Tag im Plan eines Lehrers: von, bis, Pausen. Gibt es die Zeile, ist er dabei. */
class PlanTag extends Model
{
    protected $table = 'elternsprechtag_plan_tage';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['pausen' => 'array'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }

    /** @return list<array{von: string, bis: string}> */
    public function pausenListe(): array
    {
        return array_values($this->pausen ?? []);
    }
}
