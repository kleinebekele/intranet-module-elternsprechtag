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
            'schluss_gemeldet_am' => 'datetime',
            'eltern_frist' => 'datetime',
        ];
    }

    /** Frist für die Eltern (Buchungsschluss): eingetragen, sonst Vorabend des ersten Tages, 18 Uhr. */
    public function buchungsschluss(): ?\Illuminate\Support\Carbon
    {
        return $this->eltern_frist?->copy() ?? $this->tage->first()?->datum?->copy()->subDay()->setTime(18, 0);
    }

    /** Ab dem Buchungsschluss buchen und sagen nur noch Verwaltung bzw. Lehrkraft ab. */
    public function buchungGeschlossen(): bool
    {
        return $this->buchungsschluss()?->isPast() ?? false;
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

    /** Veröffentlicht (mindestens die Wunschgespräche) – ab dann ändern Lehrer nichts mehr. */
    public function veroeffentlicht(): bool
    {
        return $this->info_versendet_am !== null;
    }

    /**
     * Zweite Stufe veröffentlicht: alle Gespräche sind buchbar. Davor (erste Stufe)
     * buchen nur persönlich eingeladene Eltern, und nur ihre Wunschgespräche.
     */
    public function fuerAlleOffen(): bool
    {
        return $this->info_rest_versendet_am !== null;
    }

    /** Erste Stufe: nur die Wunschgespräche sind veröffentlicht. */
    public function nurWunschgespraeche(): bool
    {
        return $this->veroeffentlicht() && ! $this->fuerAlleOffen();
    }

    /** Frist abgelaufen? Der Fristtag selbst zählt noch mit. */
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
