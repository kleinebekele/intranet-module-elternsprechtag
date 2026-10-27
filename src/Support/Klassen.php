<?php

namespace Intranet\Modules\Elternsprechtag\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Klassen, Lehrer, Schüler und Eltern – alles aus den Rollen, die der
 * Linear-Abgleich (Modul Verwaltung, GruppenAbgleich) pflegt:
 *
 *   linear-klasse-<klasse>-lehrer    Lehrer, die in der Klasse unterrichten (WD_Lehrer)
 *   linear-klasse-<klasse>-schueler  Schüler der Klasse
 *   linear-klasse-<klasse>-eltern    Eltern der Schüler
 *
 * plus `users_parents` für „wer sind die Eltern dieses Kindes".
 */
class Klassen
{
    private const PRAEFIX = 'linear-klasse-';

    /** Alle Klassen, für die es eine Lehrer- oder Schülerrolle gibt, natürlich sortiert. */
    public static function alle(): array
    {
        $klassen = Role::query()
            ->where('role_id', 'like', self::PRAEFIX.'%')
            ->pluck('role_id')
            ->map(fn (string $id) => self::ausRolle($id))
            ->filter()
            ->unique()
            ->values()
            ->all();

        natcasesort($klassen);

        return array_values($klassen);
    }

    /** Klassen, in denen der Benutzer laut Linear unterrichtet. */
    public static function vonLehrer(User $user): array
    {
        return $user->roles
            ->pluck('role_id')
            ->filter(fn (string $id) => str_starts_with($id, self::PRAEFIX) && str_ends_with($id, '-lehrer'))
            ->map(fn (string $id) => self::ausRolle($id))
            ->filter()
            ->values()
            ->all();
    }

    /** Klassen eines Schülers laut Linear. */
    public static function vonKind(User $kind): array
    {
        return $kind->roles
            ->pluck('role_id')
            ->filter(fn (string $id) => str_starts_with($id, self::PRAEFIX) && str_ends_with($id, '-schueler'))
            ->map(fn (string $id) => self::ausRolle($id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Alle Lehrer mit irgendeiner Linear-Lehrerrolle, nach Name.
     *
     * @return Collection<int, User>
     */
    public static function alleLehrer(): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.role_id', 'like', self::PRAEFIX.'%-lehrer'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Lehrer der Klassen, je Lehrer mit seinen Klassen daraus.
     *
     * @param  list<string>  $klassen
     * @return Collection<int, array{user: User, klassen: list<string>}>
     */
    public static function lehrer(array $klassen): Collection
    {
        $rollen = array_map(fn (string $k) => self::rolle($k, 'lehrer'), $klassen);

        return self::mitglieder($rollen)
            ->map(fn (User $u) => [
                'user' => $u,
                'klassen' => array_values(array_intersect(self::vonLehrer($u), $klassen)),
            ])
            ->sortBy(fn (array $z) => mb_strtolower($z['user']->name))
            ->values();
    }

    /**
     * Schüler der Klassen mit ihren Eltern, nach Klasse und Name sortiert.
     *
     * @param  list<string>  $klassen
     * @return Collection<int, array{kind: User, klasse: string, eltern: Collection<int, User>}>
     */
    public static function kinder(array $klassen): Collection
    {
        $zeilen = collect();

        foreach ($klassen as $klasse) {
            foreach (self::mitglieder([self::rolle($klasse, 'schueler')]) as $kind) {
                $kind->loadMissing('parents');
                $zeilen->push(['kind' => $kind, 'klasse' => $klasse, 'eltern' => $kind->parents->sortBy('name')->values()]);
            }
        }

        return $zeilen
            ->sort(fn ($a, $b) => strnatcasecmp($a['klasse'], $b['klasse']) ?: strcasecmp($a['kind']->name, $b['kind']->name))
            ->values();
    }

    /**
     * Alle Eltern der Klassen – über die Elternrolle UND über `users_parents`
     * der Schüler, damit niemand fehlt, wenn eine der beiden Quellen hinkt.
     *
     * @param  list<string>  $klassen
     * @return Collection<int, User>
     */
    public static function eltern(array $klassen): Collection
    {
        $ueberRolle = self::mitglieder(array_map(fn (string $k) => self::rolle($k, 'eltern'), $klassen));
        $ueberKinder = self::kinder($klassen)->flatMap(fn (array $z) => $z['eltern']);

        return $ueberRolle->concat($ueberKinder)
            ->unique('id')
            ->sortBy(fn (User $u) => mb_strtolower($u->name))
            ->values();
    }

    public static function rolle(string $klasse, string $art): string
    {
        return self::PRAEFIX.$klasse.'-'.$art;
    }

    /** `linear-klasse-07a-lehrer` → `07a` */
    private static function ausRolle(string $roleId): ?string
    {
        return preg_match('/^'.preg_quote(self::PRAEFIX, '/').'(.+)-(lehrer|schueler|eltern)$/', $roleId, $m) ? $m[1] : null;
    }

    /**
     * @param  list<string>  $rollen
     * @return Collection<int, User>
     */
    private static function mitglieder(array $rollen): Collection
    {
        if ($rollen === []) {
            return collect();
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('roles.role_id', $rollen))
            ->with('roles')
            ->get();
    }
}
