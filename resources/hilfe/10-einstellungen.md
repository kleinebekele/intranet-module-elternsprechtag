---
titel: Elternsprechtag einrichten und veröffentlichen
route: module.elternsprechtag.sprechtage.show
kategorie: Elternsprechtag – Verwaltung
position: 10
rollen: elternsprechtag_verwaltung, admin
---

Unter **Einstellungen** legen Sie Elternsprechtage an, behalten den Stand der Lehrkräfte im Blick
und veröffentlichen am Ende die Termine für die Eltern.

## Ablauf in vier Schritten

1. **Anlegen**: Titel, Tag(e) mit Vorschlagszeiten, beteiligte Klassen, Frist für die Lehrer und
   ein Hinweistext für die Eltern.
2. **Lehrer tragen ein**: Jede Lehrkraft füllt bis zur Frist ihren Terminplan aus und gibt ihn
   frei.
3. **Prüfen**: In der Übersicht sehen Sie, wer fertig ist, wer noch fehlt und wer abgesagt hat.
4. **Veröffentlichen**: Die Termine werden für die Eltern buchbar, die Eltern erhalten eine
   E-Mail. Ab jetzt können Lehrkräfte nichts mehr ändern.

## Anlegen und bearbeiten

- **Tage**: einer oder mehrere, je mit Vorschlag für Beginn und Ende (Standard 8–15 Uhr). Die
  Lehrkräfte übernehmen den Vorschlag und passen ihn an.
- **Klassen**: Nur Eltern dieser Klassen werden informiert und können buchen.
- **Frist für die Lehrer**: bis einschließlich dieses Tages können Lehrkräfte ihren Plan ändern.
- **Hinweis**: erscheint in der E-Mail und auf der Buchungsseite der Eltern.

Einen Tag zu löschen, entfernt auch die Zeiten der Lehrkräfte und alle gebuchten Termine an
diesem Tag – ohne Mail an die Eltern.

## Die Übersicht der Lehrer

Auf der Seite eines Sprechtags stehen alle Lehrkräfte laut Linear und alle, die selbst einen
Plan angelegt haben:

- **Klassenlehrer**: die beteiligten Klassen, für die die Lehrkraft laut Linear Klassenlehrkraft
  ist (sonst „–“).
- **Klassen**: wofür die Lehrkraft sonst noch da ist (ihre eigene Wahl, sonst die
  Linear-Vorauswahl) – ohne die Klassen aus der Spalte Klassenlehrer.
- **Stand**: noch nichts eingetragen · nicht freigegeben · ✓ freigegeben · ✗ nimmt nicht teil
  (mit Grund).
- **Gebucht / Termine**: wie viele Termine schon vergeben sind.
- Ein Klick auf den Namen öffnet den Plan der Lehrkraft – Sie handeln dann als sie und können
  alles ändern, auch nach der Frist.

## Veröffentlichen

Veröffentlicht wird in **zwei Stufen**:

1. **Wunschgespräche veröffentlichen** – nur Eltern, mit denen eine Lehrkraft unbedingt sprechen
   möchte, bekommen die Info-Mail. Sie können nur diese Gespräche buchen; die übrigen Lehrkräfte
   sehen sie, aber ohne Buchungsmöglichkeit. Alle anderen Eltern sehen noch nichts Buchbares.
2. **Alle Gespräche veröffentlichen** – jetzt geht die Mail an alle Eltern der Klassen (auch an die
   eingeladenen, damit sie wissen, dass jetzt alles buchbar ist), und alle Termine sind offen.

Zwischen den beiden Stufen zeigt der Kasten **Wunschgespräche: x von y gebucht**, welche Eltern
schon einen Termin haben. Aufgeklappt steht je Gespräch Kind, Klasse, Lehrkraft(en), Eltern und
Termin. Ein Klick auf den Namen eines Elternteils öffnet seine Buchungsseite – etwa um nach einem
Anruf den Termin für es einzutragen. Wann Stufe 2 kommt, entscheiden Sie selbst.

Gibt es keine Gesprächswünsche, heißt der Knopf einfach **Veröffentlichen**. Mit **Gleich alle
Gespräche veröffentlichen** lassen sich beide Stufen auch auf einmal erledigen.

**Pflicht für Klassenlehrkräfte:** Wer Klassenlehrkraft einer beteiligten Klasse ist, muss vorher
seinen Plan freigeben oder seine Teilnahme absagen. Solange das fehlt, ist der Knopf gesperrt;
darunter steht, wer noch fehlt, und in der Übersicht steht bei ihnen „Pflicht als
Klassenlehrkraft“. Hat eine Klasse mehrere Klassenlehrkräfte, genügt es, wenn eine davon ihren
Plan freigegeben hat – die anderen halten das Veröffentlichen dann nicht mehr auf. Alle anderen
Lehrkräfte dürfen fehlen.

Die Rückfrage nennt, wer noch keine Angabe gemacht hat – diese Kollegen können sich nach dem
Veröffentlichen nicht mehr anmelden. Gespeicherte, aber nicht freigegebene Pläne werden so
veröffentlicht, wie sie sind. Wer abgesagt hat, erscheint bei den Eltern nicht.

Lehrkräfte können ihre Pläne schon ab Stufe 1 nicht mehr ändern. Nach Stufe 2 heißt der Knopf
**Info erneut senden** und verschickt die Mail sofort noch einmal an alle.

## Gemeinsame Gespräche der Klassenlehrkräfte

Hat eine Klasse zwei Klassenlehrkräfte, führen sie gewünschte Gespräche (oder auf Wunsch alle)
gemeinsam. Das stellen die beiden in ihrem Terminplan ein; Eltern buchen sie dann als einen
Termin. Einzelheiten stehen in der Hilfe zu „Mein Terminplan“.

### Vorschau und persönliche Einladung

Unter dem Knopf lässt sich die **Vorschau der Mail** aufklappen – mit Empfänger, Betreff und Inhalt, genau so,
wie ein bestimmtes Elternteil sie bekäme. Das Elternteil wählen Sie im Suchfeld; oben stehen die
**persönlich eingeladenen** Eltern.

Persönlich eingeladen ist, wessen Kind eine Lehrkraft unter „Mit diesen Eltern möchte ich
unbedingt sprechen“ angehakt hat. Diese Eltern bekommen in der Mail einen hervorgehobenen
Abschnitt mit den Lehrkräften, die sie sprechen möchten. Die Notiz der Lehrkraft steht nicht in
der Mail. Wer seine Teilnahme abgesagt hat, zählt nicht.

Die Mail nennt nur die Klassen der eigenen Kinder. Bei nur einer Klasse entfällt die Zeile.

Den Wortlaut der Mails ändern Sie unter Verwaltung → Mailvorlagen:

- „Elternsprechtag: Info an die Eltern“
- „Elternsprechtag: Absage durch die Lehrkraft“

## Telefonische Anmeldungen

Unter **Sprechtermine** wählen Sie oben bei **Handeln als** das Elternteil und buchen für es –
siehe „Sprechtermine buchen“.

## Wer darf was

- **Einstellungen** sieht, wem der Menüpunkt im Admin zugeordnet ist (Rolle
  `elternsprechtag_verwaltung` oder Admin). Wer ihn öffnen darf, bekommt auf „Mein Terminplan“
  und „Sprechtermine“ auch **Handeln als**.
- **Mein Terminplan** und **Sprechtermine** müssen im Admin einer Rolle zugeordnet sein, die alle
  Lehrkräfte bzw. alle Eltern haben (z. B. `user`). Inhalt zeigen sie nur dem, der dort etwas hat.

## Protokoll

Jede Aktion steht im Protokoll, auch wenn die Verwaltung für jemanden gehandelt hat: Sprechtag
angelegt, geändert, gelöscht und veröffentlicht; Terminplan gespeichert, freigegeben oder
zurückgenommen; Teilnahme abgesagt; Termin gebucht, umgebucht und abgesagt – durch die Eltern
oder durch die Lehrkraft, jeweils mit dem Vermerk, ob die Mail an die Eltern rausging.
