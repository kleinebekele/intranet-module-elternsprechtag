<?php

namespace Intranet\Modules\Elternsprechtag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ein Kalendertag eines Sprechtags, mit vorgeschlagener Start-/Endzeit. */
class Tag extends Model
{
    protected $table = 'elternsprechtag_tage';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['datum' => 'date'];
    }

    public function sprechtag(): BelongsTo
    {
        return $this->belongsTo(Sprechtag::class, 'sprechtag_id');
    }

    public function beschriftung(): string
    {
        return $this->datum->locale('de')->isoFormat('dd DD.MM.YYYY');
    }
}
