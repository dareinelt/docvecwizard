# Benutzerhandbuch

## Zugang

Öffnen Sie **https://localhost:8443** im Browser. Beim ersten Aufruf erscheint
eine Warnung wegen des selbstsignierten Zertifikats – diese einmalig bestätigen.

## Hauptnavigation

Die Oberfläche ist eine Single-Page-Anwendung mit elf Ansichten:

| Ansicht | Funktion |
| --- | --- |
| **Dashboard** | Übersicht: Dokumente, Aufträge, Statistiken, letzte Aufträge |
| **Dokumente** | Liste aller indizierten Dokumente, Suche, Details, Löschen |
| **Ordner** | Dateisystem-Browser für das Eingabeverzeichnis, Upload |
| **Aufträge** | Jobs anlegen und überwachen |
| **Suche** | Semantische Suche über die Vektordatenbank |
| **Kollektionen** | Milvus-Collection-Übersicht |
| **Statistiken** | Dokumentalter, Dateitypen, Seiten, Tokens, Speicher |
| **Export / Import** | Export erstellen/herunterladen, Import |
| **TLS-Zertifikat** | Zertifikat, CSR, Import, Aktivierung |
| **Einstellungen** | Systemeinstellungen (Modell etc.) |
| **System** | Systemstatus, Metriken |

## Workflow 1: Dokumente indizieren

1. Legen Sie die Dokumente in ein Unterverzeichnis von `data/input/` ab
   (z. B. `data/input/meine-dokumente/`).
2. Öffnen Sie die Ansicht **Aufträge**.
3. Klicken Sie auf **Neuer Auftrag**.
4. Füllen Sie das Formular aus:
   - **Name**: frei wählbarer Name
   - **Quellverzeichnis**: relativer Pfad unter `data/input/`
     (z. B. `meine-dokumente`)
   - **Rekursiv**: Unterordner einschließen
   - **Embedding-Modell**: aktives Modell wählen
5. Klicken Sie auf **Erstellen**.

Der Worker erkennt den Auftrag automatisch, scannt das Verzeichnis, konvertiert
und indiziert alle Dokumente. Den Fortschritt sehen Sie in **Aufträge**.

## Workflow 2: Einzelupload

1. Öffnen Sie die Ansicht **Ordner**.
2. Navigieren Sie zum Zielverzeichnis.
3. Klicken Sie auf **Hochladen** und wählen Sie eine Datei aus.
4. Legen Sie anschließend einen Auftrag auf dieses Verzeichnis an.

Unterstützte Formate: `pdf`, `docx`, `doc`, `odt`, `rtf`, `pptx`, `ppt`, `odp`,
`html`, `htm`, `epub`, `xlsx`, `xls`, `ods`, `csv`, `txt`, `md`, `markdown`.

## Workflow 3: Semantische Suche

1. Öffnen Sie die Ansicht **Suche**.
2. Geben Sie eine natürlichsprachliche Frage ein.
3. Legen Sie ggf. die Anzahl der Treffer fest (1–50).
4. Klicken Sie auf **Suchen**.

Die Ergebnisse zeigen die ähnlichsten Text-Chunks mit Dokument und
Chunk-Position.

## Workflow 4: Dokumente verwalten

In **Dokumente**:

- **Details**: Klick auf eine Zeile zeigt Metadaten, Chunks und Vektorinfo.
- **Löschen**: Entfernt das Dokument aus MariaDB und Milvus.
- **Suche**: Filtert die Liste nach Dateiname.

## Workflow 5: Export

1. Öffnen Sie **Export / Import**.
2. Klicken Sie auf **Export erstellen**.
3. Warten Sie, bis der Export den Status `COMPLETED` erreicht.
4. Laden Sie die Datei über **Herunterladen** (Format: `tar.gz`).

Siehe [EXPORT_IMPORT.md](EXPORT_IMPORT.md) für Details.

## Statusanzeigen

- Aufträge haben die Status `CREATED`, `RUNNING`, `COMPLETED`, `FAILED`,
  `CANCELLED`. Abgebrochene Aufträge lassen sich über **Auftrag fortsetzen**
  wieder aufnehmen.
- Dokumente haben die Status `DISCOVERED`, `PENDING` (geparkt durch Abbruch),
  `PROCESSING`, `COMPLETED`, `FAILED`. Fehlgeschlagene Dokumente können in der
  Dokumentansicht über **Erneut verarbeiten** neu eingeplant werden.

## Tastatur & Bedienung

- Alle schreibenden Aktionen erfordern ein gültiges CSRF-Token (wird automatisch
  vom Frontend verwaltet).
- Hinweismeldungen erscheinen als Toast-Benachrichtigungen oben.
