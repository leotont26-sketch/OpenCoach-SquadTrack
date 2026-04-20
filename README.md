# SquadTrack – Installationsanleitung (OpenCoach)

SquadTrack ist dein einfaches Tool zur Trainings- und Spieltagsverwaltung.

Keine Datenbank. Kein unnötiger Overhead.
Einfach hochladen, starten, fertig.

---

## 1. Voraussetzungen
---------------------

Du benötigst:

- Webserver (Apache oder Nginx)
- PHP Version 8.0 oder höher (empfohlen: 8.1+ / getestet und entwickelt unter PHP 8.3)
- Schreibrechte auf bestimmte Ordner

Keine Datenbank erforderlich.

---

## 2. Dateien hochladen
-----------------------

1. ZIP-Datei herunterladen
2. Lokal entpacken
3. Alle Dateien auf den Webserver hochladen

Empfohlener Pfad:
- /squadtrack/

Beispiel:
https://deine-domain.de/squadtrack/

---

## 3. WICHTIG – Schreibrechte setzen
------------------------------------

Folgende Ordner müssen beschreibbar sein:

- /data/
- /data/backups/

### Linux (SSH):
chmod -R 775 data

Falls Probleme auftreten:
chmod -R 777 data

### FTP (z.B. FileZilla):
Rechtsklick → Dateiberechtigungen → 775 setzen

---

## 4. Erster Start
------------------

Rufe im Browser auf:

https://deine-domain.de/squadtrack/

Das System startet direkt ohne weitere Installation.

---

## 5. Admin Login
-----------------

Adminbereich:

https://deine-domain.de/squadtrack/index.php

### Standard User:
admin

### Standard Passwort:
12345678

---

## 6. !!! WICHTIG – Passwort ändern !!!
--------------------------------------

Nach dem ersten Login sofort ändern:

Dashboard → Einstellungen → Passwort ändern


---

## 7. Erste Einrichtung
-----------------------

Im Dashboard:

1. Trainer / Benutzer anlegen (optional)
2. Spieler hinzufügen
3. Trainings- und Spieltage erstellen
4. Anwesenheiten erfassen

Optional:
- Vereinsname und Logo hinterlegen
- Branding anpassen

---

## 8. Daten & Speicherung
-------------------------

Alle Daten werden lokal gespeichert:

- JSON-Dateien im /data/ Ordner

Das bedeutet:
- keine Cloud
- keine externe Abhängigkeit
- volle Kontrolle (und volle Verantwortung)

---

## 9. Backup & Sicherheit
-------------------------

Wichtig:

- Regelmäßig Backups erstellen (Dashboard → Einstellungen)
- Alternativ /data/ Ordner manuell sichern

Empfehlung:
- Backup mindestens 1x pro Woche
- Vor größeren Änderungen immer sichern

---

## 10. Updates
--------------

Bei Updates:

1. Backup erstellen
2. Neue Dateien hochladen
3. /data/ Ordner NICHT überschreiben

Fertig.

---

## 11. Häufige Probleme
-----------------------

❌ Daten werden nicht gespeichert  
→ Schreibrechte auf /data/ prüfen

❌ Login funktioniert nicht  
→ Passwort prüfen oder zurücksetzen

❌ Leere Seiten / Fehler  
→ PHP-Version prüfen (mind. 8.0)

❌ Backup funktioniert nicht  
→ /data/backups/ Rechte prüfen

---

## 12. Sicherheitshinweise
--------------------------

- Standardpasswort NIEMALS beibehalten
- Admin-Zugang nicht öffentlich teilen
- Optional: Zugriff per .htaccess absichern

---

## 13. Lizenz beachten
----------------------

SquadTrack ist Open Source, aber:

- NICHT verkaufen
- NICHT umbenennen
- Copyright MUSS erhalten bleiben

Details siehe LICENSE.txt

---

## 14. Alltag mit SquadTrack
----------------------------

Traineralltag ohne Tool:
- 10 Kinder da
- 3 fehlen
- 2 sagen zu spät ab
- einer ist plötzlich krank
- du verlierst den Überblick

Traineralltag mit SquadTrack:
- du weißt wenigstens, wer fehlt

---

## 15. Support (inoffiziell)
----------------------------

Wenn etwas nicht funktioniert:

- Erst denken
- Dann prüfen
- Dann fluchen
- Dann nochmal prüfen
- Bei absoluter Verzweiflung über https://www.tont-online.de Kontakt aufnehmen ;-)
	- OpenCoach MatchDay ist ein Freizeitprojekt, entsprechend kann es ein wenig dauern bis ich mal antworte.
	
---

OpenCoach – SquadTrack
by Spfr. Haßmersheim
