# Elternsprechtag

Modul für die modulare Intranet-Plattform (Waldorfschule).

- **Einstellungen** (Verwaltung): ein oder mehrere Tage mit Vorschlagszeiten, beteiligte
  Klassen, Frist für die Lehrer, Hinweistext. Knopf **„Eltern informieren"** schickt die
  Mailvorlage `elternsprechtag_info` an alle Eltern der Klassen (über den Ausgangskorb).
  Übersicht je Lehrer: Stand, Tage, Termine, Gesprächswünsche, Raum.
- **Mein Terminplan** (Lehrer): je Tag dabei ja/nein, Beginn, Ende, Pausen; Gesprächslänge,
  Vorbereitung zwischen den Gesprächen, Raum. Die Termine werden live berechnet. Dazu die
  Schüler der eigenen Klassen, deren Eltern man unbedingt sprechen möchte. Nach der Frist
  nur noch lesbar; die Verwaltung kann jeden Plan ändern (`?lehrer=<id>`).

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
