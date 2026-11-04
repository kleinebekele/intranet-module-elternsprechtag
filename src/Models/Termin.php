<?php

namespace Intranet\Modules\Elternsprechtag\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Ein gebuchter Gesprächstermin: Slot im Plan eines Lehrers, für ein Kind.
 * Gemeinsame Gespräche mehrerer Klassenlehrer sind je ein Termin pro Plan mit
 * derselben `gruppe` (siehe Support\Team). `allein`: gemeinsam gedacht, aber
 * nur bei diesem Klassenlehrer frei – die anderen sehen einen Hinweis.
 */
class Termin extends Model
{
    protected $table = 'elternsprechtag_termine';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['allein' => 'boolean'];
    }

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

    /**
     * Alle Termine des gemeinsamen Gesprächs (mit Plan und Lehrer), sonst nur dieser.
     *
     * @return Collection<int, Termin>
     */
    public function gruppeTermine(): Collection
    {
        return $this->gruppe
            ? self::with('plan.user')->where('gruppe', $this->gruppe)->get()
            : collect([$this->loadMissing('plan.user')]);
    }

    /** Absagen – bei einem gemeinsamen Gespräch für alle beteiligten Lehrer. */
    public function absagenMitGruppe(): void
    {
        $this->gruppe ? self::where('gruppe', $this->gruppe)->delete() : $this->delete();
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
