# ⚡ E3DC-Classic Installer & Web-Interface

**Intelligente Steuerung und Visualisierung für E3DC Hauskraftwerke auf dem Raspberry Pi.**

Dieses Projekt ist ein Erweiterungsmodul für [E3DC-Control von Eba-M](https://github.com/Eba-M/E3DC-Control).
Es bietet eine Komplettlösung zur Installation, Verwaltung und Visualisierung der E3DC-Control Software, um die Installation und Rechtevergabe so benutzerfreundlich wie möglich zu machen und eine moderne Bedienoberfläche zu schaffen.

---

## 🎯 Was macht dieses Projekt?

Es verbindet die leistungsstarke C++ Steuerung des Basis-Projekts mit einem modernen, responsiven Web-Dashboard.
Diese Classic-Version verzichtet vollständig auf speicherfressende Python-Hintergrunddienste für das Web-Interface. Das Rendering der Diagramme erfolgt ressourcenschonend pur über HTML, PHP und clientseitiges Plotly.js. Ideal für kleine oder ältere Raspberry Pis.

Die Kernfunktionen der Steuerung (von [Eba-M](https://github.com/Eba-M/E3DC-Control)):
*   **🔋 Intelligentes Laden:** Der Speicher wird basierend auf Wetterprognosen und dynamischen Strompreisen geladen.
*   **📉 Kostenoptimierung:** Nutzung günstiger Strompreisfenster zum Nachladen.
*   **☀️ Prognosebasiert:** Vermeidung von Abregelungsverlusten durch vorausschauendes Lademanagement.

Zusätzliche Funktionen dieses Moduls:
*   **📊 Visualisierung:** Ein umfassendes Web-Dashboard zeigt Live-Werte, Historie und Prognosen direkt im Browser gerendert.
*   **📈 Interaktive Diagramme:** Klickbare Kacheln für schnelle, ressourcenschonende Analysen in der Weboberfläche.
*   **🔌 Wallbox-Steuerung:** Steuerung der E3DC Wallbox.

---

## 📋 Voraussetzungen

Bevor du startest, stelle sicher, dass folgende Punkte erfüllt sind:

*   **Hardware:** Raspberry Pi (Empfohlen: Pi 4 oder Pi 5, läuft auch auf Pi Zero 2 W) mit SD-Karte oder SSD.
*   **Betriebssystem:** Raspberry Pi OS Lite (Bullseye oder neuer, 64-bit empfohlen).
*   **Python:** Python 3.7+ (Der Installer richtet automatisch ein isoliertes Virtual Environment ein).
*   **Netzwerk:** Der Pi muss im gleichen Netzwerk wie das E3DC Hauskraftwerk sein und Internetzugriff haben.
*   **Zugriff:** SSH-Zugriff auf den Pi.

*Hinweis: Ein Webserver (Apache/PHP) ist nicht zwingend vorinstalliert nötig, da der Installer diesen auf Wunsch automatisch einrichtet.*

---

## 🚀 Installation

Die Installation erfolgt bequem über die Kommandozeile.

### Schritt 1: System aktualisieren & Git installieren

Melde dich per SSH auf deinem Raspberry Pi an und führe folgende Befehle aus:

```bash
sudo apt update
sudo apt install -y git
```

### Schritt 2: Repository klonen

Lade den Installer herunter:

```bash
cd ~
git clone https://github.com/A9xxx/E3DC-Classic.git Install
```

### Schritt 3: Installer starten

Wechsle in das Verzeichnis und starte das Setup:

```bash
sudo python3 fix_bom.py
cd Install
sudo python3 installer_main.py
```

Im Menü wählst du für eine Neuinstallation am besten die Option **"Alles installieren"**. Der Assistent führt dich durch die Einrichtung von Abhängigkeiten, der E3DC-Software, dem Webserver und der Konfiguration.

---

## ️ Wartung & Updates

Der Installer dient auch als Wartungstool. Starte ihn jederzeit erneut (`sudo python3 installer_main.py`), um Updates einzuspielen, Berechtigungen zu reparieren oder Backups zu verwalten.

Für automatisierte Abläufe (z.B. via Cronjob) gibt es den Headless-Modus: `sudo python3 installer_main.py --unattended`


---
