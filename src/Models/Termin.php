<?php

namespace Intranet\Modules\Elternsprechtag\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Ein gebuchter Gesprächstermin: Slot im Plan eines Lehrers, für ein Kind. */
class Termin extends Model
{
    protected $table = 'elternsprechtag_termine';

    protected $guarded = [];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }

    public function kind(): BelongsTo
    {
        return $this->belongsTo(User::class, 'kind_user_id');
    }

    public function eltern(): BelongsTo
    {
        return $this->belongsTo(User::class, 'eltern_user_id');
    }

    public function beginn(): Carbon
    {
        return $this->tag->datum->copy()->setTimeFromTimeString($this->von);
    }

    public function beschriftung(): string
    {
        return $this->tag->beschriftung().', '.$this->von.'–'.$this->bis.' Uhr';
    }
}
