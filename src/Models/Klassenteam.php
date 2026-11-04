<?php

namespace Intranet\Modules\Elternsprechtag\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Einstellung der Klassenlehrer einer Klasse für einen Sprechtag: welche
 * Gespräche sie gemeinsam führen – `wunsch` (nur gewünschte) oder `alle`.
 * Ohne Eintrag gilt `wunsch` (siehe Support\Team).
 */
class Klassenteam extends Model
{
    protected $table = 'elternsprechtag_klassenteams';

    protected $guarded = [];
}
