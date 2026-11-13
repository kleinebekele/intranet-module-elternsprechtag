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
 *   linear-klasse-<klasse>-klassenlehrer  Klassenlehrer (WD_Lehrer.Funktion, bis zu zwei)
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

    /**
     * Ist der Benutzer laut Linear Klassenlehrer einer dieser Klassen? Rolle
     * `linear-klasse-<klasse>-klassenlehrer` (WD_Lehrer.Funktion, GruppenAbgleich).
     *
     * @param  list<string>  $klassen
     */
    public static function istKlassenlehrer(User $user, array $klassen): bool
    {
        $rollen = array_map(fn (string $k) => self::PRAEFIX.$k.'-klassenlehrer', $klassen);

        return $user->roles->pluck('role_id')->intersect($rollen)->isNotEmpty();
    }

    /**
     * Die Klassen aus $klassen, für die der Benutzer laut Linear Klassenlehrer ist.
     *
     * @param  list<string>  $klassen
     * @return list<string>
     */
    public static function alsKlassenlehrer(User $user, array $klassen): array
    {
        return array_values(array_filter($klassen, fn (string $k) => $user->roles->contains('role_id', self::rolle($k, 'klassenlehrer'))));
    }

    /**
     * Alle Klassenlehrer der Klassen.
     *
     * @param  list<string>  $klassen
     * @return Collection<int, User>
     */
    public static function klassenlehrer(array $klassen): Collection
    {
        return self::mitglieder(array_map(fn (string $k) => self::rolle($k, 'klassenlehrer'), $klassen));
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

    /** Grundrolle „Lehrer" aus dem Linear-Import (Adresse.Lehrer = J). */
    public const ROLLE_LEHRER = 'teacher';

    /**
     * Alle Lehrer – mit Linear-Klassenrolle oder mit der Grundrolle „Lehrer" –, nach Name.
     *
     * @return Collection<int, User>
     */
    public static function alleLehrer(): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.role_id', 'like', self::PRAEFIX.'%-lehrer'))
            ->get()
            ->concat(self::ohneKlasse())
            ->unique('id')
            ->sortBy(fn (User $u) => Namen::schluessel($u->name))
            ->values();
    }

    /**
     * Lehrer mit der Grundrolle „Lehrer", denen Linear keine Klasse zuordnet
     * (WD_Lehrer leer). Sie wählen ihre Klassen im Terminplan selbst.
     *
     * @return Collection<int, User>
     */
    public static function ohneKlasse(): Collection
    {
        return User::query()
            ->whereNull('gesperrt_am')
            ->whereHas('roles', fn ($q) => $q->where('roles.role_id', self::ROLLE_LEHRER))
            ->whereDoesntHave('roles', fn ($q) => $q->where('roles.role_id', 'like', self::PRAEFIX.'%-lehrer'))
            ->with('roles')
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
            ->sortBy(fn (array $z) => Namen::schluessel($z['user']->name))
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
                $zeilen->push(['kind' => $kind, 'klasse' => $klasse, 'eltern' => $kind->parents->sortBy(fn (User $u) => Namen::schluessel($u->name))->values()]);
            }
        }

        return $zeilen
            ->sort(fn ($a, $b) => strnatcasecmp($a['klasse'], $b['klasse']) ?: strcmp(Namen::schluessel($a['kind']->name), Namen::schluessel($b['kind']->name)))
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
            ->sortBy(fn (User $u) => Namen::schluessel($u->name))
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
