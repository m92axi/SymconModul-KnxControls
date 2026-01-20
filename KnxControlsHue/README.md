# KnxControlsHue
Dieses Modul stellt eine Verbindung zwischen KNX-Tastern/Sensoren und Philips Hue Lampen her.
Es ermöglicht die vollständige Steuerung (Schalten, Dimmen, Farbe, Farbtemperatur) sowie eine komfortable Szenenverwaltung, bei der Lichtstimmungen nicht nur abgerufen, sondern auch über KNX gespeichert ("gelernt") werden können.


### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [Hinweis](#8-hinweis)

### 1. Funktionsumfang

* **Schalten**: An/Aus über KNX (DPT 1.001).
* **Dimmen**:
  * Relatives Dimmen über KNX DPT 3.007 (Dimming Control). In IP-Symcon wird dies durch eine 4-Bit Variable (Start/Stop) und eine 1-Bit Variable (Richtung) dargestellt.
  * Unterstützung für 1-Tasten-Dimmen durch Rückmeldung der Dimm-Richtung.
  * Konfigurierbare Dimm-Geschwindigkeit und Schrittweite.
* **Tunable White (Farbtemperatur)**:
  * Steuerung über absolute Kelvin-Werte (DPT 7.600).
  * Relatives Dimmen der Farbtemperatur (wärmer/kälter) (DPT 3.007).
* **Farbe (RGB)**: Steuerung über absolute RGB-Werte (DPT 232.600).
* **Szenenverwaltung**:
  * Abrufen von Szenen (1-64) (DPT 17.001 / 18.001).
  * **Lern-Funktion**: Speichern der aktuellen Lichtstimmung auf eine Szenennummer, wenn eine definierte "Steuerungs-Variable" (z.B. langer Tastendruck) aktiv ist.
  * Mapping-Tabelle zur Zuordnung von KNX-Szenennummern zu Hue-Farben/Helligkeiten.
  * Import von Szenen aus IP-Symcon Variablen-Profilen.
* **Rückmeldung**: Senden von Status, Helligkeit, Farbe und Farbtemperatur zurück auf den KNX-Bus (Status-Objekte).

### 2. Voraussetzungen

- IP-Symcon ab Version 8.1
- Installiertes und konfiguriertes Philips Hue Modul (oder kompatibel).

### 3. Software-Installation
(Aktuell nur über ein Manueles kopieren der dateien in den Modul Order der Symcon-Installation)
* Über den Module Store das 'KnxControls'-Modul installieren.
* Alternativ über das Module Control die URL des Repositories hinzufügen.

### 4. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' kann das 'KnxControlsHue'-Modul mithilfe des Schnellfilters gefunden werden.  
	- Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

| Name | Beschreibung |
| :--- | :--- |
| **Hue Licht Instanz** | Die IP-Symcon Instanz der zu steuernden Hue Lampe. |
| **KNX Szenen Eingänge** | Liste von KNX-Variablen (Byte) für die Szenennummer (DPT 17.001). Optional kann eine "Steuerungs Variable" (Bit) hinzugefügt werden, um den Lern-Modus (Speichern) zu aktivieren. |
| **KNX Schalt Eingänge** | Liste von KNX-Variablen (Bit) für An/Aus (DPT 1.001). |
| **KNX Dimm Eingänge** | Konfiguration für relatives Dimmen (DPT 3.007). Benötigt die IP-Symcon Variablen für "Schritt/Stop" (4-Bit) und "Richtung" (1-Bit). Optional "Richtungsumschalt Variable" für 1-Tasten-Dimmen. |
| **Dimm Einstellungen** | Schrittweite und Intervall für die Dimm-Rampe. |
| **KNX Tunable White Eingänge** | Variablen für absolute Farbtemperatur (Kelvin) (DPT 7.600). |
| **KNX Tunable White Dimm Eingänge** | Konfiguration für relatives Dimmen der Farbtemperatur (ähnlich Helligkeits-Dimmen) (DPT 3.007). |
| **KNX Farb Eingänge** | Variablen für absolute Farbe (RGB) (DPT 232.600). |
| **Szenen Zuordnung** | Tabelle, die KNX-Szenennummern den Hue-Werten (Farbe, Helligkeit) zuordnet. |
| **Rückmelde Variablen** | Hier können KNX-Instanzen/Variablen hinterlegt werden, auf die der aktuelle Status der Hue Lampe gesendet wird (z.B. für Visu oder LED-Feedback am Taster). |

### 5. Statusvariablen und Profile

Das Modul selbst legt keine eigenen Statusvariablen zur Anzeige an, sondern verknüpft bestehende KNX-Variablen mit der Hue-Instanz.
Es werden Timer für die Dimm-Funktionen intern verwaltet.

### 6. WebFront

Das Modul dient primär der Logik im Hintergrund. Die Visualisierung erfolgt über die Hue-Instanz selbst oder die verknüpften KNX-Elemente.

### 7. PHP-Befehlsreferenz

Das Modul arbeitet ereignisbasiert. Es gibt keine spezifischen Befehle für den Endanwender, die im Skript genutzt werden müssen.

### 8. Hinweis

Dieser Code wurde zum großen Teil mit Hilfe von KI-Assistenz erstellt.