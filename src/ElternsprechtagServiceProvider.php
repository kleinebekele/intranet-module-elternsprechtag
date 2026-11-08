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
    /** Allgemeine Info an alle Eltern (zweite Stufe bzw. alles auf einmal). */
    public const VORLAGE_INFO = 'elternsprechtag_info';

    /** Einladung zu den Wunschgesprächen (erste Stufe, nur persönlich eingeladene Eltern). */
    public const VORLAGE_WUNSCH = 'elternsprechtag_wunsch';

    public const VORLAGE_ABSAGE = 'elternsprechtag_absage';

    /** Terminliste als PDF an die Lehrkraft (Plan voll, Buchungsschluss, spätere Änderung). */
    public const VORLAGE_TERMINLISTE = 'elternsprechtag_terminliste';

    public function manifest(): ModuleManifest
    {
        return ModuleManifest::make('elternsprechtag', 'Elternsprechtag', icon: 'calendar')
            ->rolle(Rechte::VERWALTUNG, 'Elternsprechtag: Verwaltung')
            ->item('plan', 'Mein Terminplan', 'module.elternsprechtag.plan.index', icon: 'calendar')
            ->item('termine', 'Sprechtermine', 'module.elternsprechtag.eltern.index', icon: 'users')
            ->item('sprechtage', 'Einstellungen', 'module.elternsprechtag.sprechtage.index', icon: 'list')
            ->lesend('plan.zeigen', 'plan.pdf','plan.speichern', 'plan.abschliessen', 'plan.teilnahme', 'plan.absagen', 'plan.notiz','eltern.buchen', 'eltern.assistent', 'eltern.vorschlag', 'eltern.absagen', 'eltern.keinezeit')
            ->stufe(Zugriffsstufe::Verwalten, 'sprechtage.info');
    }

    public function register(): void
    {
        parent::register();

        // Task Elternsprechtag/Buchungsschluss (Vorabend 18 Uhr) – nur, wenn Ekkon im Core steckt.
        if (class_exists(\App\Ekkon\Support\TaskRegistry::class)) {
            $this->app->singletonIf(\App\Ekkon\Support\TaskRegistry::class);
            $this->app->make(\App\Ekkon\Support\TaskRegistry::class)->addSource(
                $this->moduleBasePath().'/src/Tasks',
                __NAMESPACE__.'\Tasks',
                'do1emu/module-elternsprechtag',
                'elternsprechtag',
            );
        }
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
                'elternsprechtag.keine_zeit' => 'Elternsprechtag: Eltern melden „keine Zeit" bzw. nehmen es zurück',
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
                titel: 'Elternsprechtag: Info an alle Eltern',
                beschreibung: 'Geht an alle Eltern der beteiligten Klassen, sobald alle Gespräche veröffentlicht werden (zweite Stufe oder „Gleich alle Gespräche veröffentlichen"). Persönlich eingeladene Eltern bekommen sie auch – mit dem Abschnitt {{ einladung }}.',
                platzhalter: [
                    'name' => 'Name des Elternteils',
                    'sprechtag' => 'Titel des Sprechtags',
                    'tage' => 'Die Tage, z. B. „Do 12.11.2026, Fr 13.11.2026"',
                    'klassen' => 'Klassen der Kinder dieses Elternteils',
                    'klassen_zeile' => 'Zeile „Klassen: …" unter den Terminen; entfällt, wenn es nur eine Klasse ist',
                    'hinweis' => 'Hinweistext, der beim Sprechtag eingetragen ist',
                    'einladung' => 'Persönliche Einladung: Lehrkräfte, die diese Eltern gern sprechen möchten (bei allen anderen leer)',
                    'link' => 'Link zur Terminbuchung im Intranet',
                ],
                betreff: 'Elternsprechtag: {{ sprechtag }}',
                html: self::INFO_HTML,
                text: self::INFO_TEXT,
                beispiele: [
                    'sprechtag' => 'Elternsprechtag Herbst',
                    'tage' => 'Do 12.11.2026, Fr 13.11.2026',
                    'klassen' => '5a, 7a',
                    'klassen_zeile' => '<br><strong>Klassen:</strong> 5a, 7a',
                    'hinweis' => 'Die Terminbuchung öffnet in Kürze im Intranet.',
                    'einladung' => '<div style="margin:0 0 16px;padding:12px 16px;background:#eef2ff;border-left:4px solid #4f46e5;border-radius:4px;"><p style="margin:0 0 8px;"><strong>Persönliche Einladung:</strong> Folgende Lehrkräfte möchten Sie gern sprechen:</p><ul style="margin:0 0 8px;padding-left:20px;"><li>Frau Muster – für Max Muster</li></ul><p style="margin:0;">Bitte buchen Sie dort möglichst einen Termin.</p></div>',
                ],
                modul: 'Elternsprechtag',
                ausloeser: 'Elternsprechtag-Info',
            ));
            $register->registrieren(new \App\Mail\Vorlagen\VorlagenDefinition(
                schluessel: self::VORLAGE_WUNSCH,
                titel: 'Elternsprechtag: Einladung zu Wunschgesprächen',
                beschreibung: 'Erste Stufe: geht über „1. Wunschgespräche veröffentlichen" nur an Eltern, mit denen eine Lehrkraft unbedingt sprechen möchte. Sie können vorerst nur diese Gespräche buchen; die allgemeine Info folgt mit der zweiten Stufe.',
                platzhalter: [
                    'name' => 'Name des Elternteils',
                    'sprechtag' => 'Titel des Sprechtags',
                    'tage' => 'Die Tage, z. B. „Do 12.11.2026, Fr 13.11.2026"',
                    'klassen' => 'Klassen der Kinder dieses Elternteils',
                    'klassen_zeile' => 'Zeile „Klassen: …" unter den Terminen; entfällt, wenn es nur eine Klasse ist',
                    'hinweis' => 'Hinweistext, der beim Sprechtag eingetragen ist',
                    'gespraeche' => 'Liste der Lehrkräfte, die diese Eltern sprechen möchten, je mit Kind',
                    'link' => 'Link zur Terminbuchung im Intranet',
                ],
                betreff: 'Elternsprechtag: {{ sprechtag }} – persönliche Einladung',
                html: self::WUNSCH_HTML,
                text: self::WUNSCH_TEXT,
                beispiele: [
                    'sprechtag' => 'Elternsprechtag Herbst',
                    'tage' => 'Do 12.11.2026, Fr 13.11.2026',
                    'klassen' => '5a, 7a',
                    'klassen_zeile' => '<br><strong>Klassen:</strong> 5a, 7a',
                    'hinweis' => 'Die Gespräche finden in den Klassenräumen statt.',
                    'gespraeche' => '<div style="margin:0 0 16px;padding:12px 16px;background:#eef2ff;border-left:4px solid #4f46e5;border-radius:4px;"><ul style="margin:0;padding-left:20px;"><li>Frau Muster – für Max Muster</li></ul></div>',
                ],
                modul: 'Elternsprechtag',
                ausloeser: 'Elternsprechtag-Einladung',
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
            $register->registrieren(new \App\Mail\Vorlagen\VorlagenDefinition(
                schluessel: self::VORLAGE_TERMINLISTE,
                titel: 'Elternsprechtag: Terminliste an die Lehrkraft',
                beschreibung: 'Geht mit der Terminliste als PDF an die Lehrkraft, sobald ihr Plan voll ist (nach einer Absage beim nächsten Vollwerden erneut), am Vorabend um 18 Uhr beim Buchungsschluss an alle übrigen teilnehmenden Lehrkräfte und danach bei jeder Änderung durch die Verwaltung.',
                platzhalter: [
                    'name' => 'Name der Lehrkraft',
                    'sprechtag' => 'Titel des Sprechtags',
                    'tage' => 'Die Tage, z. B. „Do 12.11.2026, Fr 13.11.2026"',
                    'anlass' => 'Warum die Mail kommt (Plan voll / Buchungsschluss / Änderung)',
                    'stand' => 'z. B. „12 von 14 Terminen gebucht"',
                    'link' => 'Link zu „Mein Terminplan"',
                ],
                betreff: 'Elternsprechtag: {{ sprechtag }} – deine Terminliste',
                html: self::TERMINLISTE_HTML,
                text: self::TERMINLISTE_TEXT,
                beispiele: [
                    'sprechtag' => 'Elternsprechtag Herbst',
                    'tage' => 'Do 12.11.2026, Fr 13.11.2026',
                    'anlass' => 'die Terminbuchung ist geschlossen; Eltern können jetzt nichts mehr buchen oder absagen.',
                    'stand' => '12 von 14 Terminen gebucht',
                ],
                modul: 'Elternsprechtag',
                ausloeser: Support\Lehrerinfo::QUELLE,
            ));
        });
    }

    private const INFO_HTML = <<<'HTML'
<p style="margin:0 0 16px;font-size:16px;">Liebe Eltern, hallo {{ name }}!</p>
<p style="margin:0 0 16px;">Wir laden Sie herzlich zum <strong>{{ sprechtag }}</strong> ein.</p>
<p style="margin:0 0 16px;"><strong>Termine:</strong> {{ tage }}{{ klassen_zeile }}</p>
<p style="margin:0 0 16px;">{{ hinweis }}</p>
{{ einladung }}
<p style="margin:0 0 24px;">
  <a href="{{ link }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:bold;">Termine buchen</a>
</p>
<p style="margin:0;color:#6b7280;font-size:14px;">Mit freundlichen Grüßen<br>Ihr Kollegium</p>
HTML;

    private const WUNSCH_HTML = <<<'HTML'
<p style="margin:0 0 16px;font-size:16px;">Liebe Eltern, hallo {{ name }}!</p>
<p style="margin:0 0 16px;">Zum <strong>{{ sprechtag }}</strong> möchten folgende Lehrkräfte gern mit Ihnen sprechen:</p>
{{ gespraeche }}
<p style="margin:0 0 16px;">Damit diese Gespräche sicher einen Platz bekommen, können Sie sie schon jetzt – vor allen anderen Eltern – buchen. Bitte buchen Sie dort möglichst bald einen Termin. Die übrigen Lehrkräfte sind etwas später buchbar; dazu erhalten Sie noch eine E-Mail.</p>
<p style="margin:0 0 16px;"><strong>Termine:</strong> {{ tage }}{{ klassen_zeile }}</p>
<p style="margin:0 0 16px;">{{ hinweis }}</p>
<p style="margin:0 0 24px;">
  <a href="{{ link }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:bold;">Termin buchen</a>
</p>
<p style="margin:0;color:#6b7280;font-size:14px;">Mit freundlichen Grüßen<br>Ihr Kollegium</p>
HTML;

    private const WUNSCH_TEXT = <<<'TEXT'
Liebe Eltern, hallo {{ name }}!

Zum {{ sprechtag }} möchten folgende Lehrkräfte gern mit Ihnen sprechen:
{{ gespraeche }}

Damit diese Gespräche sicher einen Platz bekommen, können Sie sie schon jetzt – vor allen anderen Eltern – buchen. Bitte buchen Sie dort möglichst bald einen Termin. Die übrigen Lehrkräfte sind etwas später buchbar; dazu erhalten Sie noch eine E-Mail.

Termine: {{ tage }}{{ klassen_zeile }}

{{ hinweis }}

Termin buchen: {{ link }}

Mit freundlichen Grüßen
Ihr Kollegium
TEXT;

    private const TERMINLISTE_HTML = <<<'HTML'
<p style="margin:0 0 16px;font-size:16px;">Hallo {{ name }},</p>
<p style="margin:0 0 16px;">zum <strong>{{ sprechtag }}</strong> ({{ tage }}): {{ anlass }}</p>
<p style="margin:0 0 16px;"><strong>Stand:</strong> {{ stand }}. Deine Terminliste hängt als PDF an.</p>
<p style="margin:0 0 24px;">
  <a href="{{ link }}" style="display:inline-block;background:#4f46e5;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:bold;">Mein Terminplan</a>
</p>
<p style="margin:0;color:#6b7280;font-size:14px;">Viele Grüße<br>Deine Verwaltung</p>
HTML;

    private const TERMINLISTE_TEXT = <<<'TEXT'
Hallo {{ name }},

zum {{ sprechtag }} ({{ tage }}): {{ anlass }}

Stand: {{ stand }}. Deine Terminliste hängt als PDF an.

Mein Terminplan: {{ link }}

Viele Grüße
Deine Verwaltung
TEXT;

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

Termine: {{ tage }}{{ klassen_zeile }}

{{ hinweis }}

{{ einladung }}

Termine buchen: {{ link }}

Mit freundlichen Grüßen
Ihr Kollegium
TEXT;
}
