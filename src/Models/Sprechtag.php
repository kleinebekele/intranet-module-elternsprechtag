<?php

namespace Intranet\Modules\Elternsprechtag\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein Elternsprechtag – ein oder mehrere Tage, beteiligte Klassen, Frist für
 * die Lehrer und der Stand der Eltern-Info.
 */
class Sprechtag extends Model
{
    protected $table = 'elternsprechtage';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'klassen' => 'array',
            'lehrer_frist' => 'date',
            'info_versendet_am' => 'datetime',
            'info_rest_versendet_am' => 'datetime',
        ];
    }

    public function tage(): HasMany
    {
        return $this->hasMany(Tag::class, 'sprechtag_id')->orderBy('datum');
    }

    public function plaene(): HasMany
    {
        return $this->hasMany(Plan::class, 'sprechtag_id');
    }

    /** @return list<string> */
    public function klassenListe(): array
    {
        return array_values($this->klassen ?? []);
    }

    /** Frist abgelaufen? Der Fristtag selbst zählt noch mit. */
    /** Veröffentlicht = Eltern informiert; ab dann ändern Lehrer nichts mehr. */
    public function veroeffentlicht(): bool
    {
        return $this->info_versendet_am !== null;
    }

    /** Ab wann die übrigen (nicht persönlich eingeladenen) Eltern Mail bekommen und buchen dürfen. */
    public function restFaelligAm(): ?\Illuminate\Support\Carbon
    {
        return $this->info_versendet_am?->copy()->addMinutes((int) $this->vorsprung_minuten);
    }

    /** Vorsprung vorbei bzw. die übrigen Eltern sind schon informiert? */
    public function fuerAlleOffen(): bool
    {
        return $this->info_rest_versendet_am !== null || ($this->restFaelligAm()?->isPast() ?? false);
    }

    public function fristVorbei(): bool
    {
        return $this->lehrer_frist !== null && $this->lehrer_frist->endOfDay()->isPast();
    }

    /** Alle Tage vorbei – dann gehört der Sprechtag ins Archiv. */
    public function vorbei(): bool
    {
        $letzter = $this->tage->last();

        return $letzter !== null && $letzter->datum->endOfDay()->isPast();
    }

    /** „Do 12.11.2026, Fr 13.11.2026" */
    public function tageText(): string
    {
        return $this->tage->map(fn (Tag $t) => $t->beschriftung())->join(', ');
    }
}
