---
version: "1.0"
date: 2026-11-06
---

# Handbuch: Übersetzung

Übersetzungen laufen über DeepL oder OpenAI. Welcher Dienst aktiv ist, steht in der Extension-Konfiguration. Das Protokoll unter **Web** ist nur für Administratoren.

## Aufruf

Die Übersetzung schreibt in die Sprachversion des Datensatzes. Jeder Aufruf landet im Protokoll: Tabelle, Datensatz, Feld, Sprachpaar und Dienst.

Ohne API-Schlüssel schlägt der jeweilige Dienst fehl. Der Fehler steht dann im Protokoll.

## Protokoll

Das Modul zeigt die letzten Einträge. Es ersetzt nicht die normale Übersetzungsansicht im Seitenmodul, sondern dokumentiert die automatischen Läufe.
