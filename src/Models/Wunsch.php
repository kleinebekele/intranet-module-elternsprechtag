<?php

namespace Intranet\Modules\Elternsprechtag\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * „Mit diesen Eltern will ich unbedingt sprechen" – gespeichert am Kind, weil
 * Eltern zu einem Kind kommen und ein Elternteil mehrere Kinder haben kann.
 */
class Wunsch extends Model
{
    protected $table = 'elternsprechtag_wuensche';

    protected $guarded = [];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kind_user_id');
    }
}
