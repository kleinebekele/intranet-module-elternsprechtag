<?php

namespace Intranet\Modules\Elternsprechtag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Eine geblockte Zeit im Plan: nur die Verwaltung kann sie buchen. */
class Blockade extends Model
{
    protected $table = 'elternsprechtag_blockaden';

    protected $guarded = [];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
