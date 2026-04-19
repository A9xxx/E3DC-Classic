"""
E3DC-Control Installer - Selbstaktualisierung

Prüft beim Start, ob eine neue Version des Installers auf GitHub verfügbar ist.
"""

import os
import sys
import json
import subprocess
import tempfile
import shutil
from urllib.request import urlopen, Request
from urllib.error import URLError, HTTPError
import re

from .core import register_command
from .utils import run_command
from .installer_config import get_install_user
from .logging_manager import get_or_create_logger, log_task_completed, log_error, log_warning

# Repository-Informationen
GITHUB_REPO = "A9xxx/E3DC-Classic"
RELEASES_API = f"https://api.github.com/repos/{GITHUB_REPO}/releases/latest"
SCRIPT_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
INSTALLER_DIR = SCRIPT_DIR
VERSION_FILE = os.path.join(SCRIPT_DIR, "VERSION")
DEBUG = False
USER_AGENT = "E3DC-Control-Installer/1.0"
update_logger = get_or_create_logger("self_update")


def get_installed_version():
    """
    Holt die aktuelle Version des Installers.
    
    Versucht zunächst, die VERSION-Datei zu lesen.
    Falls nicht vorhanden, nutzt Git-Commit.
    """
    # Versuche VERSION-Datei zu lesen
    if os.path.exists(VERSION_FILE):
        try:
            with open(VERSION_FILE, "r") as f:
                version = f.read().strip()
                if version:
                    return version
        except Exception:
            pass
    
    # Fallback: Git-Commit
    try:
        result = run_command(f"cd {SCRIPT_DIR} && git rev-parse --short HEAD", timeout=5)
        if result['success'] and result['stdout'].strip():
            return result['stdout'].strip()
    except Exception:
        pass
    
    return "unknown"


def get_latest_release_info():
    """
    Holt Informationen über das neueste Release von GitHub.
    Da E3DC-Classic u.U. keine Releases nutzt, laden wir direkt vom master/main Branch.
    
    Returns:
        dict mit 'version', 'download_url', 'body' oder None bei Fehler
    """
    try:
        for branch in ["master", "main"]:
            try:
                commit_api = f"https://api.github.com/repos/{GITHUB_REPO}/commits/{branch}"
                request = Request(commit_api, headers={"User-Agent": USER_AGENT})
                with urlopen(request, timeout=10) as response:
                    data = json.loads(response.read().decode())
                    sha = data.get('sha', '')
                    if sha:
                        return {
                            'version': sha[:7],
                            'prerelease': False,
                            'body': data.get('commit', {}).get('message', ''),
                            'download_url': f"https://github.com/{GITHUB_REPO}/archive/refs/heads/{branch}.zip",
                            'assets': []
                        }
            except HTTPError as e:
                # 404 bedeutet Branch nicht vorhanden, probiere den nächsten
                if e.code == 404:
                    continue
                print(f"[!] HTTP-Fehler ({branch}): {e}")
                
    except URLError as e:
        print(f"[!] Netzwerkfehler beim Abrufen der Release-Informationen: {e}")
        log_warning("self_update", f"Netzwerkfehler beim Abrufen der Release-Informationen: {e}")
    except json.JSONDecodeError:
        print("[!] Fehler beim Parsen der Release-Informationen")
        log_warning("self_update", "Fehler beim Parsen der Release-Informationen")
    except Exception as e:
        print(f"[!] Fehler beim Abrufen der Release-Informationen: {e}")
        log_warning("self_update", f"Fehler beim Abrufen der Release-Informationen: {e}")
    
    return None


def download_release(download_url):
    """
    Lädt die Release-ZIP herunter.
    
    Returns:
        Pfad zur heruntergeladenen Datei oder None bei Fehler
    """
    try:
        print("-> Lade Release herunter…")
        update_logger.info(f"Starte Download von: {download_url}")
        
        temp_dir = tempfile.gettempdir()
        zip_path = os.path.join(temp_dir, f"E3DC-Install-{os.getpid()}.zip")
        
        request = Request(download_url, headers={"User-Agent": USER_AGENT})
        with urlopen(request, timeout=60) as response:
            with open(zip_path, 'wb') as out_file:
                out_file.write(response.read())
        
        if os.path.exists(zip_path):
            size_mb = os.path.getsize(zip_path) / (1024 * 1024)
            print(f"[OK] Download abgeschlossen ({size_mb:.1f} MB)")
            update_logger.info(f"Download abgeschlossen: {size_mb:.1f} MB")
            return zip_path
        
        return None
    
    except URLError as e:
        print(f"[Err] Netzwerkfehler beim Download: {e}")
        log_error("self_update", f"Netzwerkfehler beim Download: {e}", e)
    except Exception as e:
        print(f"[Err] Fehler beim Download: {e}")
        log_error("self_update", f"Fehler beim Download: {e}", e)
    
    return None


def extract_release(zip_path, new_version):
    """
    Entpackt die Release-ZIP und führt Update durch.
    
    Args:
        zip_path: Pfad zur heruntergeladenen ZIP-Datei
        new_version: Die neue Version, die in VERSION-Datei geschrieben wird
    
    Returns:
        True bei Erfolg, False bei Fehler
    """
    try:
        import zipfile
        print("-> Entpacke Update…")
        update_logger.info("Entpacke Update-ZIP...")
        temp_extract = os.path.join(tempfile.gettempdir(), f"e3dc_update_{os.getpid()}")
        if DEBUG:
            print(f"[DEBUG] Entpacke nach: {temp_extract}")
        # Entpacke ZIP immer aus dem übergebenen Pfad (sollte im Temp liegen)
        with zipfile.ZipFile(zip_path, 'r') as zip_ref:
            zip_ref.extractall(temp_extract)

        # Finde das Installer-Verzeichnis in der ZIP
        extracted_items = os.listdir(temp_extract)
        if DEBUG:
            print(f"[DEBUG] ZIP-Inhalt: {extracted_items}")
        # Suche nach dem Verzeichnis, das installer_main.py enthält
        for root, dirs, files in os.walk(temp_extract):
            if "installer_main.py" in files:
                src_installer = root
                break

        if not src_installer or not os.path.exists(src_installer):
            print(f"[Err] Installer-Verzeichnis nicht in ZIP gefunden: {src_installer}")
            log_error("self_update", "Installer-Verzeichnis nicht in ZIP gefunden.")
            shutil.rmtree(temp_extract, ignore_errors=True)
            return False

        print(f"-> Aktualisiere Installer-Verzeichnis aus: {src_installer}")
        update_logger.info(f"Aktualisiere Installer-Verzeichnis aus: {src_installer}")
        print(f"  Ziel-Verzeichnis (wird aktualisiert): {INSTALLER_DIR}")

        # Sicherung der Konfiguration
        config_to_preserve = None
        config_path = os.path.join(INSTALLER_DIR, "Installer", "installer_config.json")
        if os.path.exists(config_path):
            try:
                with open(config_path, 'r', encoding='utf-8') as f:
                    config_to_preserve = f.read()
                print("  -> Sichern der installer_config.json")
            except Exception as e:
                print(f"  [!] Sicherung der installer_config.json fehlgeschlagen: {e}")

        # Sicherung der aktuellen Version (immer!)
        backup_dir = INSTALLER_DIR + ".backup"
        if os.path.exists(backup_dir):
            shutil.rmtree(backup_dir, ignore_errors=True)
        if os.path.exists(INSTALLER_DIR):
            try:
                shutil.copytree(INSTALLER_DIR, backup_dir)
                print(f"  -> Sicherung erstellt: {backup_dir}")
                update_logger.info(f"Backup erstellt: {backup_dir}")
            except Exception as e:
                print(f"  [!] Sicherung fehlgeschlagen: {e}")
                log_warning("self_update", f"Backup vor Update fehlgeschlagen: {e}")
        else:
            print(f"  [!] Kein bestehendes Installationsverzeichnis für Backup gefunden: {INSTALLER_DIR}")

        # Ersetze Installer-Verzeichnis (Inhalt)
        try:
            if not os.path.exists(INSTALLER_DIR):
                os.makedirs(INSTALLER_DIR)
            
            # Kopiere neuen Inhalt ins bestehende Verzeichnis
            # Wir löschen NICHTS vorher, um versehentliches Löschen von config-Dateien zu vermeiden
            for item in os.listdir(src_installer):
                s = os.path.join(src_installer, item)
                d = os.path.join(INSTALLER_DIR, item)
                
                # Überspringe .git
                if item == ".git":
                    continue
                    
                if os.path.isdir(s):
                    # dirs_exist_ok=True sorgt dafür, dass Unterordner zusammengeführt werden
                    shutil.copytree(s, d, dirs_exist_ok=True)
                else:
                    # Normale Dateien werden überschrieben
                    shutil.copy2(s, d)
            
            print("[OK] Update erfolgreich installiert")
            update_logger.info("Dateien erfolgreich aktualisiert.")

            # Wiederherstellen der Konfiguration
            if config_to_preserve:
                try:
                    # Ensure the "Installer" directory exists
                    os.makedirs(os.path.dirname(config_path), exist_ok=True)
                    with open(config_path, 'w', encoding='utf-8') as f:
                        f.write(config_to_preserve)
                    print("[OK] Wiederherstellen der installer_config.json")
                except Exception as e:
                    print(f"[!] Wiederherstellen der installer_config.json fehlgeschlagen: {e}")
            
            # Aktualisiere VERSION-Datei mit neuer Version
            try:
                with open(VERSION_FILE, 'w') as f:
                    f.write(new_version)
                print(f"[OK] VERSION-Datei aktualisiert: {new_version}")
            except Exception as e:
                print(f"[!] Konnte VERSION-Datei nicht aktualisieren: {e}")
            
            # Setze Besitzrechte für den Installationsordner
            try:
                install_user = get_install_user()
                subprocess.run(["chown", "-R", f"{install_user}:{install_user}", INSTALLER_DIR], check=True)
                print(f"[OK] Rechte für {INSTALLER_DIR} auf {install_user}:{install_user} gesetzt")
            except Exception as e:
                print(f"[!] Konnte Rechte nicht setzen: {e}")
            # Entferne alte Sicherung
            if os.path.exists(backup_dir):
                shutil.rmtree(backup_dir, ignore_errors=True)
            # Cleanup
            shutil.rmtree(temp_extract, ignore_errors=True)
            if os.path.exists(zip_path):
                os.remove(zip_path)
            return True
        except Exception as e:
            print(f"[Err] Fehler beim Verschieben der Dateien: {e}")
            log_error("self_update", f"Fehler beim Verschieben der Dateien: {e}", e)
            # Restore Backup
            if os.path.exists(backup_dir):
                print("-> Stelle Sicherung wieder her…")
                try:
                    if os.path.exists(INSTALLER_DIR):
                        shutil.rmtree(INSTALLER_DIR)
                    shutil.copytree(backup_dir, INSTALLER_DIR)
                    update_logger.info("Sicherung nach Fehler wiederhergestellt.")
                    print("[OK] Sicherung wiederhergestellt")
                except Exception as restore_e:
                    print(f"[Err] Fehler beim Wiederherstellen: {restore_e}")
                    log_error("self_update", f"Fehler beim Wiederherstellen der Sicherung: {restore_e}", restore_e)
            return False
    
    except ImportError:
        print("[Err] zipfile-Modul nicht verfügbar")
        log_error("self_update", "zipfile-Modul nicht verfügbar.")
    except Exception as e:
        print(f"[Err] Fehler beim Entpacken: {e}")
        log_error("self_update", f"Fehler beim Entpacken: {e}", e)
    
    return False


def is_newer_version(latest, installed):
    def parse(v):
        parts = [p for p in re.split(r'[^0-9]+', v) if p]
        return [int(p) for p in parts] if parts else []
    lv = parse(latest)
    iv = parse(installed)
    if not lv or not iv:
        return latest != installed
    for i in range(max(len(lv), len(iv))):
        l = lv[i] if i < len(lv) else 0
        r = iv[i] if i < len(iv) else 0
        if l > r:
            return True
        if l < r:
            return False
    return False


def check_and_update(silent=False, check_only=False):
    """
    Hauptfunktion: Prüft auf Updates und führt diese durch.
    Nutzt nun direkt das allgemeine E3DC-Update (git pull), da Classic keine ZIP-Releases mehr benötigt.
    """
    try:
        from .update import check_for_updates, update_e3dc, count_missing_commits
        
        missing = count_missing_commits()
        if check_only:
            return (missing is not None and missing > 0)
            
        print("\n=== Installer/System Update Prüfung ===")
        if missing is None or missing == 0:
            if not silent:
                print("[OK] Das System (inklusive Installer) ist auf dem neuesten Stand.\n")
            return False
            
        if not silent:
            choice = input(f"Es fehlen {missing} Commit(s). Möchtest du jetzt aktualisieren? (j/n): ").strip().lower()
            if choice != "j":
                print("-> Update übersprungen.\n")
                return False
                
        # Nutzt die robuste Update-Funktion, die git pull durchführt
        update_e3dc(headless=silent)
        
        # Fordere nach Update Neustart an
        print("\n-> Installer wird neu gestartet…\n")
        installer_main = os.path.join(INSTALLER_DIR, 'installer_main.py')
        os.execv(sys.executable, [sys.executable, installer_main])
        return True
    except Exception as e:
        print(f"[!] Fehler beim Update: {e}\n")
        return False

def run_self_update_check():
    """
    API für Menu-Integration.
    Startet Update-Check und bietet Menü an.
    """
    print()
    check_and_update(silent=False)


# Registriere als Menü-Befehl
register_command("1", "Installer aktualisieren", run_self_update_check, sort_order=10)
