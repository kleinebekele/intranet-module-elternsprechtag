# Elternsprechtag

Modul für die modulare Intranet-Plattform (Waldorfschule).

- **Einstellungen** (Verwaltung): ein oder mehrere Tage mit Vorschlagszeiten, beteiligte
  Klassen, Frist für die Lehrer, Hinweistext. Knopf **„Veröffentlichen"** gibt die Termine aller
  Lehrer für die Eltern frei und schickt die Mailvorlage `elternsprechtag_info` an alle Eltern
  der Klassen (über den Ausgangskorb). Übersicht je Lehrer: Stand, Tage, Termine,
  Gesprächswünsche, Raum.
- **Mein Terminplan** (Lehrer): je Tag dabei ja/nein, Beginn, Ende, Pausen; Gesprächsdauer,
  Vorbereitungszeit, Raum. Die Termine werden live berechnet. Dazu die Schüler der eigenen
  Klassen, deren Eltern man unbedingt sprechen möchte. **„Speichern und freigeben"** meldet den
  Plan der Verwaltung als fertig. Nach der Frist oder dem Veröffentlichen nur noch lesbar; die
  Verwaltung kann jeden Plan ändern (`?lehrer=<id>`). Standard je Gespräch: 15 Minuten.
  **„Terminliste als PDF"**: alle Slots je Tag mit Kind, Klasse, Eltern und Notizspalte zum Ausdrucken. Gebuchte Termine kann die Lehrkraft absagen (auch nach der Frist); das
  Elternteil bekommt die Mailvorlage `elternsprechtag_absage` über den Ausgangskorb.
  **„Teilnahme absagen"** (mit Grund für die Verwaltung): Plan verschwindet für die Eltern, alle
  gebuchten Termine werden mit Mail abgesagt; „Doch teilnehmen" macht es rückgängig.

## Datenquelle

Klassen, Lehrer, Schüler und Eltern kommen aus den Rollen des Linear-Abgleichs
(`linear-klasse-<klasse>-lehrer|schueler|eltern`, Modul Verwaltung) und `users_parents`.
Lehrer ist, wer irgendeine Linear-Lehrerrolle hat. Für welche Klassen eines Sprechtags er da ist,
wählt er im Terminplan selbst; seine Linear-Klassen sind nur die Vorauswahl.

## Rechte

- Menüpunkt „Einstellungen": Rolle `elternsprechtag_verwaltung` (bzw. Admin) im Admin zuordnen.
- Menüpunkt „Mein Terminplan": einer Rolle zuordnen, die alle Lehrer haben (z. B. `user`).
  Sichtbar ist er allein nach dieser Zuordnung; Inhalt hat er nur für Lehrer (Linear-Lehrerrolle).
- **Sprechtermine** (Eltern): je Kind die Lehrer, die für dessen Klasse da sind, mit ihren freien
  Uhrzeiten. Buchen, umbuchen, absagen. Offen, sobald die Eltern informiert wurden; je Lehrer und
  Kind ein Termin, die Termine einer Familie überschneiden sich nicht.

## Handeln als

Wer „Einstellungen" aufrufen darf, sieht auf „Mein Terminplan" und „Sprechtermine" eine Auswahl
„Handeln als" (`?lehrer=` bzw. `?eltern=`) – zum Prüfen und für Telefonbuchungen. Die Verwaltung
bucht auch vor dem Öffnen der Buchung.
