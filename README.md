# Wartungsplaner für IP-Symcon

Ein IP-Symcon Modul zur Planung und Verwaltung von Wartungsaufgaben mit automatischen Benachrichtigungen.

## Features

- ✅ Verwaltung von Wartungsaufgaben mit Titel und Beschreibung
- 📅 Flexible Intervalle: Wöchentlich, Monatlich, Quartalsweise, Halbjährlich, Jährlich
- 🔔 Tägliche automatische Benachrichtigungen zu konfigurierbarer Zeit
- 📱 Integration mit VISU_PostNotificationEx für WebFront-Benachrichtigungen
- 🎨 Moderne HTML-Visualisierung über HTML-SDK mit dunklem Design
- ♻️ Automatische Berechnung des nächsten Fälligkeitsdatums nach Erledigung
- 🌍 Vollständige Mehrsprachigkeit (Deutsch/Englisch) über translate()
- 🔄 Automatische Aktualisierung der Visualisierung bei Änderungen

## Installation

1. Laden Sie das Modul in Ihr IP-Symcon Verzeichnis hoch (z.B. `C:\IP-Symcon\modules\Wartungsplaner\`)
2. Öffnen Sie die Symcon Management Console
3. Gehen Sie zu "Kern Instanzen" → "Module Control"
4. Klicken Sie auf "Modul laden" und wählen Sie das Verzeichnis aus
5. Erstellen Sie eine neue Instanz über "Objekt hinzufügen" → "Instanz" → "Wartungsplaner"

## Konfiguration

### Modul-Einstellungen

1. **Benachrichtigungszeit**: Stellen Sie die Uhrzeit ein, zu der täglich geprüft werden soll, ob Aufgaben fällig sind (0-23 Uhr)

2. **Wartungsaufgaben**: Fügen Sie Ihre Wartungsaufgaben hinzu mit:
   - **Titel**: Name der Wartungsaufgabe
   - **Beschreibung**: Detaillierte Beschreibung der durchzuführenden Arbeiten
   - **Intervall**: Wählen Sie zwischen:
     - Wöchentlich
     - Monatlich
     - Quartalsweise (3 Monate)
     - Halbjährlich (6 Monate)
     - Jährlich
   - **Startdatum**: Datum der ersten Fälligkeit im Format YYYY-MM-DD (z.B. 2025-10-10)

### Visualisierung einrichten

1. Platzieren Sie die Wartungsplaner-Instanz in einer Kategorie, die in der Kachel-Visualisierung angezeigt wird
2. Die Instanz wird über das HTML-SDK als eigene Kachel dargestellt – sowohl als normale Kachel als auch im Vollbild (geöffnete Kachel, ab Symcon 9.1)
3. Die HTML-Visualisierung zeigt automatisch alle Aufgaben an mit:
   - Farblicher Kennzeichnung (Rot = Fällig, Orange = Bald fällig, Grün = Geplant)
   - Fälligkeitsdatum und verbleibende Tage
   - "Erledigt"-Button für fällige Aufgaben

## Verwendung

### Aufgaben verwalten

- Öffnen Sie die Instanz-Konfiguration
- Fügen Sie neue Aufgaben über die Liste hinzu
- Bearbeiten oder löschen Sie bestehende Aufgaben

### Aufgaben abschließen

1. Öffnen Sie die Visualisierung im WebFront
2. Klicken Sie bei einer fälligen Aufgabe auf "Erledigt"
3. Das neue Fälligkeitsdatum wird automatisch basierend auf dem Startdatum und Intervall berechnet
4. Sie erhalten eine Bestätigung und die Ansicht wird aktualisiert

### Benachrichtigungen

- Jeden Tag zur eingestellten Zeit werden fällige Aufgaben geprüft
- Für jede fällige Aufgabe wird eine WebFront-Benachrichtigung gesendet
- Die Benachrichtigung enthält Titel und Beschreibung der Aufgabe

## PHP-Funktionen

Das Modul stellt folgende Funktionen bereit:

```php
// Prüft fällige Aufgaben und sendet Benachrichtigungen
WP_CheckDueTasks($instanceId);

// Markiert eine Aufgabe als erledigt und berechnet neues Fälligkeitsdatum
WP_CompleteTask($instanceId, $taskId);

// Gibt JSON-Liste aller Aufgaben mit Status zurück
$taskList = WP_GetTaskList($instanceId);
```

## Beispiel-Aufgabe

**Titel**: Heizungswartung

**Beschreibung**: Jährliche Inspektion und Wartung der Heizungsanlage durch Fachbetrieb

**Intervall**: Jährlich

**Startdatum**: 2025-10-01

## Technische Details

- **Modul-ID**: {A7B6C5D4-3E2F-1A0B-8C9D-6E5F4A3B2C1D}
- **Library-ID**: {B8C9D5E7-4F3A-2B1C-9A8D-7E6F5C4B3A2D}
- **Prefix**: WP
- **Mindest-Version**: IP-Symcon 9.1 (HTML-SDK mit Vollbild-Darstellung)

## Troubleshooting

**Benachrichtigungen werden nicht gesendet**:
- Prüfen Sie, ob die Funktion VISU_PostNotificationEx verfügbar ist
- Stellen Sie sicher, dass ein WebFront aktiv ist

**Aufgaben erscheinen nicht in der Visualisierung**:
- Überprüfen Sie, ob Aufgaben in der Konfiguration angelegt wurden
- Prüfen Sie die Instanz-ID in der HTML-Box-URL

**Fälligkeitsdatum wird nicht korrekt berechnet**:
- Überprüfen Sie das Format des Startdatums (YYYY-MM-DD)
- Stellen Sie sicher, dass ein gültiges Intervall ausgewählt wurde

## Lizenz

Dieses Modul ist für die private und kommerzielle Nutzung frei verfügbar.

## Support

Bei Problemen oder Fragen erstellen Sie bitte ein Issue im Repository oder kontaktieren Sie den Support.
