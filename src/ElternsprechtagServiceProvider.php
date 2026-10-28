<?php

namespace Intranet\Modules\Elternsprechtag;

use App\Modules\Support\ModuleManifest;
use App\Modules\Support\ModuleServiceProvider;
use App\Modules\Support\Zugriffsstufe;
use Intranet\Modules\Elternsprechtag\Support\Rechte;

/**
 * Anmelde-Klasse des Elternsprechtags.
 *
 * „Einstellungen" (Anlegen, Eltern informieren, Übersicht der Lehrer) hängt
 * am Menüpunkt `sprechtage` und damit an den Rollen, die ihm im Admin zugeordnet
 * sind. „Mein Terminplan" sieht jeder mit einer Linear-Lehrerrolle; wessen Plan
 * er öffnen und ändern darf, prüft Support\Rechte – deshalb stehen die
 * Planrouten auf „lesen".
 */
class ElternsprechtagServiceProvider extends ModuleServiceProvider
{
    public const VORLAGE_INFO = 'elternsprechtag_info';

    public const VORLAGE_ABSAGE = 'elternsprechtag_absage';

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('elternsprechtag', 'Elternsprechtag', icon: 'calendar')
            ->rolle(Rechte::VERWALTUNG, 'Elternsprechtag: Verwaltung')
            ->item('plan', 'Mein Terminplan', 'module.elternsprechtag.plan.index', icon: 'calendar')
            ->item('termine', 'Sprechtermine', 'module.elternsprechtag.eltern.index', icon: 'users')
            ->item('sprechtage', 'Einstellungen', 'module.elternsprechtag.sprechtage.index', icon: 'list')
            ->lesend('plan.zeigen', 'plan.pdf','plan.speichern', 'plan.abschliessen', 'plan.teilnahme', 'plan.absagen', 'eltern.buchen', 'eltern.absagen')
            ->stufe(Zugriffsstufe::Verwalten, 'sprechtage.info');
    }

    public function boot(): void
    {
        parent::boot();

        $this->vorlagenAnmelden();

        if (class_exists(\App\Support\Audit::class)) {
            \App\Support\Audit::benennen([
                'elternsprechtag.angelegt' => 'Elternsprechtag: angelegt',
                'elternsprechtag.geaendert' => 'Elternsprechtag: geändert',
                'elternsprechtag.geloescht' => 'Elternsprechtag: gelöscht',
                'elternsprechtag.info' => 'Elternsprechtag: veröffentlicht (Eltern informiert)',
                'elternsprechtag.plan' => 'Elternsprechtag: Terminplan gespeichert',
                'elternsprechtag.termin' => 'Elternsprechtag: Termin gebucht',
                'elternsprechtag.absage' => 'Elternsprechtag: Termin abgesagt',
                'elternsprechtag.absage_lehrer' => 'Elternsprechtag: Termin von der Lehrkraft abgesagt',
                'elternsprechtag.freigabe' => 'Elternsprechtag: Terminplan freigegeben bzw. Freigabe zurückgenommen',
                'elternsprechtag.teilnahme' => 'Elternsprechtag: Teilnahme abgesagt bzw. wieder zugesagt',
            ]);
        }
    }

    private function vorlagenAnmelden(): void
    {
        if (! class_exists(\App\Mail\Vorlagen\VorlagenRegister::class)) {
            return;
        }

        $this->app->booted(function (): void {
            $register = $this->app->make(\App\Mail\Vorlagen\VorlagenRegister::class);
            $register->registrieren(new \App\Mail\Vorlagen\VorlagenDefinition(
                schluessel: self::VORLAGE_INFO,
                titel: 'Elternsprechtag: Info an die Eltern',
                beschreibung: 'Geht über den Knopf „Veröffentlichen" an alle Eltern der beteiligten Klassen.',
                platzhalter: [
                    'name' => 'Name des Elternteils',
                    'sprechtag' => 'Titel des Sprechtags',
                    'tage' => 'Die Tage, z. B. „Do 12.11.2026, Fr 13.11.2026"',
                    'klassen' => 'Beteiligte Klassen',
                    'hinweis' => 'Hinweistext, der beim Sprechtag eingetragen ist',
                    'link' => 'Link zur Terminbuchung im Intranet',
                ],
                betreff: 'Elternsprechtag: {{ sprechtag }}',
                html: self::INFO_HTML,
                text: self::INFO_TEXT,
                beispiele: [
                    'sprechtag' => 'Elternsprechtag Herbst',
                    'tage' => 'Do 12.11.2026, Fr 13.11.2026',
                    'klassen' => '5a, 6a, 7a',
                    'hinweis' => 'Die Terminbuchung öffnet in Kürze im Intranet.',
                ],
                modul: 'Elternsprechtag',
                ausloeser: 'Elternsprechtag-Info',
            ));
            $register->registrieren(new \App\Mail\Vorlagen\VorlagenDefinition(
                schluessel: self::VORLAGE_ABSAGE,
                titel: 'Elternsprechtag: Absage durch die Lehrkraft',
                beschreibung: 'Geht an das Elternteil, das gebucht hat, wenn die Lehrkraft den Termin absagt.',
                platzhalter: [
                    'name' => 'Name des Elternteils',
                    'sprechtag' => 'Titel des Sprechtags',
                    'lehrer' => 'Name der Lehrkraft',
                    'kind' => 'Name des Kindes',
                    'termin' => 'Der abgesagte Termin, z. B. „Do 12.11.2026, 14:00–14:15 Uhr"',
                    'link' => 'Link zur Terminbuchung im Intranet',
                ],
                betreff: 'Elternsprechtag: Termin bei {{ lehrer }} abgesagt',
                html: self::ABSAGE_HTML,
                text: self::ABSAGE_TEXT,
                beispiele: [
                    'sprechtag' => 'Elternsprechtag Herbst',
                    'lehrer' => 'Frau Muster',
                    'kind' => 'Max Muster',
                    'termin' => 'Do 12.11.2026, 14:00–14:15 Uhr',
                ],
                modul: 'Elternsprechtag',
                ausloeser: 'Elternsprechtag-Absage',
            ));
        });
    }

    private const INFO_HTML = <<<'HTML'
<p style="margin:0 0 16px;font-size:16px;">Liebe Eltern, hallo {{ name }}!</p>
<p style="margin:0 0 16px;">Wir laden Sie herzlich zum <strong>{{ sprechtag }}</strong> ein.</p>
<p style="margin:0 0 8px;"><strong>Termine:</strong> {{ tage }}</p>
<p style="margin:0 0 16px;"><strong>Klassen:</strong> {{ klassen }}</p>
<p style="margin:0 0 16px;">{{ hinweis }}</p>
<p style="margin:0 0 24px;">
  <a href="{{ link }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:bold;">Termine buchen</a>
</p>
<p style="margin:0;color:#6b7280;font-size:14px;">Mit freundlichen Grüßen<br>Ihr Kollegium</p>
HTML;

    private const ABSAGE_HTML = <<<'HTML'
<p style="margin:0 0 16px;font-size:16px;">Liebe Eltern, hallo {{ name }}!</p>
<p style="margin:0 0 16px;">Leider muss {{ lehrer }} Ihren Gesprächstermin zum {{ sprechtag }} absagen:</p>
<p style="margin:0 0 16px;"><strong>{{ termin }}</strong> (für {{ kind }})</p>
<p style="margin:0 0 16px;">Bitte buchen Sie bei Bedarf einen neuen Termin:</p>
<p style="margin:0 0 24px;">
  <a href="{{ link }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:bold;">Neuen Termin buchen</a>
</p>
<p style="margin:0;color:#6b7280;font-size:14px;">Mit freundlichen Grüßen<br>Ihr Kollegium</p>
HTML;

    private const ABSAGE_TEXT = <<<'TEXT'
Liebe Eltern, hallo {{ name }}!

Leider muss {{ lehrer }} Ihren Gesprächstermin zum {{ sprechtag }} absagen:

{{ termin }} (für {{ kind }})

Bitte buchen Sie bei Bedarf einen neuen Termin: {{ link }}

Mit freundlichen Grüßen
Ihr Kollegium
TEXT;

    private const INFO_TEXT = <<<'TEXT'
Liebe Eltern, hallo {{ name }}!

Wir laden Sie herzlich zum {{ sprechtag }} ein.

Termine: {{ tage }}
Klassen: {{ klassen }}

{{ hinweis }}

Termine buchen: {{ link }}

Mit freundlichen Grüßen
Ihr Kollegium
TEXT;
}
