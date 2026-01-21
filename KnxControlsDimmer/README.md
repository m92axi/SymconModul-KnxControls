# KnxControlsDimmer
Dieses Modul stellt eine generische Verbindung zwischen KNX-Tastern/Sensoren und beliebigen dimmbaren Lichtern in IP-Symcon her. Es ist für alle Geräte geeignet, die über eine separate Status- (Boolean) und Helligkeits-Variable (Integer) gesteuert werden.

Es ermöglicht die vollständige Steuerung (Schalten, Dimmen) sowie eine komfortable Szenenverwaltung, bei der Lichtstimmungen nicht nur abgerufen, sondern auch über KNX gespeichert ("gelernt") werden können.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Einrichten der Instanzen in IP-Symcon](#2-einrichten-der-instanzen-in-ip-symcon)
3. [Statusvariablen und Profile](#3-statusvariablen-und-profile)
4. [PHP-Befehlsreferenz](#4-php-befehlsreferenz)
5. [Hinweis](#5-hinweis)

### 1. Funktionsumfang

* **Schalten**: An/Aus über KNX (DPT 1.001).
* **Dimmen**:
  * Absolute Helligkeit über KNX (DPT 5.001).
  * Relatives Dimmen über KNX DPT 3.007 (Dimming Control).
  * Unterstützung für 1-Tasten-Dimmen durch Rückmeldung der Dimm-Richtung.
  * Konfigurierbare Dimm-Geschwindigkeit und Schrittweite.
* **Szenenverwaltung**:
  * Abrufen von Szenen (1-64) (DPT 17.001 / 18.001).
  * **Lern-Funktion**: Speichern der aktuellen Helligkeit auf eine Szenennummer.
  * Mapping-Tabelle zur Zuordnung von KNX-Szenennummern zu Helligkeitswerten.
* **Rückmeldung**: Senden von Status und Helligkeit zurück auf den KNX-Bus (Status-Objekte).

### 2. Einrichten der Instanzen in IP-Symcon

 Unter 'Instanz hinzufügen' kann das 'KnxControlsDimmer'-Modul mithilfe des Schnellfilters gefunden werden.

__Konfigurationsseite__:

| Name | Beschreibung |
| :--- | :--- |
| **Status Variable (Boolean)** | Die IP-Symcon Variable (Boolean), die den An/Aus-Zustand des Lichts steuert. |
| **Brightness Variable (Integer)** | Die IP-Symcon Variable (Integer), die die Helligkeit des Lichts steuert. |
| **Max. Brightness Value** | Der maximale Wert der Helligkeits-Variable (z.B. 100 für Prozent oder 255 für 8-Bit-Werte). |
| **KNX Szenen Eingänge** | Liste von KNX-Variablen für die Szenennummer. Optional mit Steuerungs-Variable für den Lern-Modus. |
| **KNX Schalt Eingänge** | Liste von KNX-Variablen (Bit) für An/Aus. |
| **KNX Absolut Eingänge** | Liste von KNX-Variablen (Byte) für absolute Helligkeit (0-100% oder 0-255). |
| **KNX Dimm Eingänge** | Konfiguration für relatives Dimmen (DPT 3.007). |
| **Dimm Einstellungen** | Schrittweite und Intervall für die Dimm-Rampe. |
| **Szenen Zuordnung** | Tabelle, die KNX-Szenennummern den Helligkeitswerten zuordnet. |
| **Rückmelde Variablen** | KNX-Instanzen/Variablen für die Rückmeldung von Status und Helligkeit. |

### 3. Statusvariablen und Profile

Das Modul selbst legt keine eigenen Statusvariablen zur Anzeige an, sondern verknüpft bestehende KNX-Variablen mit den Ziel-Variablen des Lichts.

### 4. PHP-Befehlsreferenz

Das Modul arbeitet ereignisbasiert. Es gibt keine spezifischen Befehle für den Endanwender.

### 5. Hinweis

Dieser Code wurde zum großen Teil mit Hilfe von KI-Assistenz erstellt.