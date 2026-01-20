# KnxControlsSonos
Dieses Modul verbindet KNX-Taster/Sensoren mit einer Sonos-Instanz in IP-Symcon.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Einrichten der Instanzen in IP-Symcon](#2-einrichten-der-instanzen-in-ip-symcon)
3. [Statusvariablen und Profile](#3-statusvariablen-und-profile)
4. [WebFront](#4-webfront)
5. [PHP-Befehlsreferenz](#5-php-befehlsreferenz)
6. [Hinweis](#6-hinweis)

### 1. Funktionsumfang

* **Steuerung**:
  * Play/Pause über KNX (DPT 1.001).
  * Lautstärke absolut (0-100%) über KNX (DPT 5.001).
  * Mute/Unmute über KNX (DPT 1.001).
  * Lautstärke relativ (Dimmen) über KNX (DPT 3.007).
  * Szenenauswahl über KNX (DPT 17.001 / 18.001).
* **Mapping**: Zuordnung von KNX-Szenennummern zu Sonos-Radiosendern oder Audio-Datei-URLs (z.B. für Türklingel-Sounds).
* **Rückmeldung**: Senden von Status (Play/Pause), Mute und Lautstärke (%) zurück auf den KNX-Bus.
* **Besonderheiten**: Logik zur Vermeidung von Rückkopplungsschleifen bei der Lautstärkeänderung und Timer für relatives Dimmen.

### 2. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' kann das 'KnxControlsSonos'-Modul mithilfe des Schnellfilters gefunden werden.  
	- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

| Name | Beschreibung |
| :--- | :--- |
| **Sonos Instanz auswählen** | Die IP-Symcon Instanz des zu steuernden Sonos Lautsprechers. |
| **Play/Pause Inputs** | Liste von KNX-Variablen (Bit) für Play/Pause (DPT 1.001). |
| **Mute Inputs** | Liste von KNX-Variablen (Bit) für Mute/Unmute (DPT 1.001). |
| **Volume Inputs (Absolute %)** | Liste von KNX-Variablen (Byte) für absolute Lautstärke (DPT 5.001). |
| **Relative Lautstärke Eingänge (Dimmen)** | Konfiguration für relatives Dimmen (DPT 3.007). Benötigt die IP-Symcon Variablen für "Schritt/Stop" (4-Bit) und "Richtung" (1-Bit). Optional "Richtungsumschaltung" für 1-Tasten-Dimmen. |
| **Scene Inputs** | Liste von KNX-Variablen (Byte) für die Szenennummer (DPT 17.001). |
| **Status Feedback (Play/Pause)** | KNX-Variable (Bit) für die Rückmeldung des Wiedergabestatus (1=Play, 0=Pause/Stop). |
| **Mute Feedback** | KNX-Variable (Bit) für die Rückmeldung des Mute-Status. |
| **Volume Feedback (%)** | KNX-Variable (Byte) für die Rückmeldung der aktuellen Lautstärke. |
| **Schrittgröße für Lautstärkeänderung** | Prozentwert, um den die Lautstärke beim Dimmen geändert wird. |
| **Stations-Szenen-Zuordnung** | Zuordnung von KNX-Szenennummern zu Sonos-Favoriten/Sendern. |
| **Benachrichtigungsklänge URL Szenen-Zuordnung** | Zuordnung von KNX-Szenennummern zu Audio-URLs (z.B. MP3) für Benachrichtigungen. |

### 3. Statusvariablen und Profile

Das Modul selbst legt keine eigenen Statusvariablen zur Anzeige an, sondern verknüpft bestehende KNX-Variablen mit der Sonos-Instanz.

### 4. Visualisierung

Das Modul dient primär der Logik im Hintergrund. Die Visualisierung erfolgt über die Sonos-Instanz selbst oder die verknüpften KNX-Elemente.

### 5. PHP-Befehlsreferenz

Das Modul arbeitet ereignisbasiert. Es gibt keine spezifischen Befehle für den Endanwender, die im Skript genutzt werden müssen.

### 6. Hinweis

Dieser Code wurde zum großen Teil mit Hilfe von KI-Assistenz erstellt.