# Medien mit rsync übertragen

Medien werden immer durch die zuständige Administration übertragen. Der Import kopiert keine Mediendateien. Die ZIP-Datei der Version 2 enthält `site.json`, `tables.sql`, `users.csv` und `media.json`. Alte Pakete müssen neu exportiert werden; `--uploads` und `--skip-uploads` werden nicht mehr angeboten.

## 1. Quelle und Snapshot vorbereiten

Schreibzugriffe auf Datenbank und Medien vor dem Export stoppen. Einen passenden, unveränderlichen Mediensnapshot aufbewahren, bis Übertragung und Prüfung abgeschlossen sind. Das Medienmanifest allein ist keine Datensicherung. Besonders bei einer Migration innerhalb derselben Installation muss dieser Snapshot **vor dem manuellen Löschen der bisherigen Website** außerhalb ihres Upload-Verzeichnisses gesichert sein.

```sh
wp rrze-migration wizard export --site-id=5
```

Das Manifest enthält Quellverzeichnis und Basis-URL sowie relative Dateinamen, Größen und SHA-256-Prüfsummen. Es erfasst alle regulären Dateien im Medienverzeichnis, nicht nur registrierte Attachments. Symbolische Links, ausführbare Dateien und Server-Konfigurationen bleiben unzulässig. Nicht benötigte Plugin-Arbeitsverzeichnisse lassen sich mit `--exclude-upload-dirs=wp-migrate-db` ausdrücklich ausschließen. Schutzdateien auf der Quelle nicht löschen. Bei der modernen Netzwerk-Hauptseite wird `sites` ausgeschlossen, damit Medien anderer Websites nicht in den Transfer geraten.

Ein laufender Export ist kein atomarer Snapshot. Er erkennt Änderungen während einzelner Dateiprüfungen, kann aber gleichzeitige Datenbank- und Dateisystemänderungen nicht vollständig ausschließen. Die Schreibpause bzw. eine konsistente Quellsicherung ist daher eine betriebliche Voraussetzung.

## 2. Daten importieren

```sh
wp rrze-migration wizard import
```

Der Import legt ausschließlich eine neue Website an. Bestehende Websites und Restressourcen werden nicht übernommen. Die tatsächliche neue Site-ID bestimmt das reservierte, zunächst leere Medienziel. WordPress wird für diese neue Website in einem eigenen Prozess geladen, um ihren effektiven Upload-Pfad und ihre Basis-URL zu prüfen. Die Medien-URL-Zuordnung verwendet die im Manifest erfasste Basis-URL und läuft serialisierungssicher ausschließlich auf den neuen Tabellen; dabei werden auch vollständige, protokollrelative, root-relative und JSON-escaped Medien-URLs berücksichtigt.

Historische zusätzliche Medien-Basis-URLs und spezielle Plugin-Verweise müssen vorab vereinheitlicht oder gesondert geprüft werden; die Attachment-Prüfung ist keine vollständige Prüfung aller eingebetteten Medien in Seiteninhalten.

Standard-Multisite-Ziele und Filter mit unverändertem eigenem Medienbasispfad werden unterstützt. Individuelle/gemeinsame Ziel-Basisverzeichnisse oder Legacy-Zielstrukturen benötigen weiterhin einen Adapter. Das Kopieren per rsync hebt die Anforderungen an ein eindeutig zur neuen Website gehörendes Ziel nicht auf.

Nach erfolgreichem Datenimport lautet der Status `media_pending`. Das private Laufverzeichnis enthält zusätzlich `media.json` und die NUL-getrennte Dateiliste `media-files.txt`. Diese Dateien nicht bearbeiten; die späteren Befehle prüfen sie gegen das aufbewahrte Exportpaket.

## 3. Transfer auf dem Zielserver vorbereiten

```sh
wp rrze-migration media plan RUN_ID \
  --run-dir=/srv/private/rrze-migrations \
  --source-host=operator@quellhost \
  --source-dir=/srv/snapshots/website-media
```

`--source-host` bezeichnet einen SSH-Host bzw. Alias, bei Bedarf mit Benutzer. Ports und weitere SSH-Einstellungen gehören in die SSH-Konfiguration. Ohne `--source-dir` wird das im Export erfasste Quellverzeichnis verwendet. Ohne beide Angaben zeigen die Befehle einen ausdrücklich zu ersetzenden `SOURCE_USER@SOURCE_HOST`-Platzhalter. Remote-Transfers setzen rsync ab 3.0 auf beiden Hosts voraus, um Argumente mit `--protect-args` sicher zu übertragen.

Für einen auf dem Zielserver vorhandenen bzw. eingebundenen Snapshot:

```sh
wp rrze-migration media plan RUN_ID --source-dir=/mnt/snapshot/website-media
```

Die ausgegebenen Befehle auf dem **Zielserver** ausführen: zuerst `Preview`, dann nach Prüfung `Transfer`. Die abschließenden Schrägstriche bedeuten, dass die Inhalte ins Zielverzeichnis kopiert werden. Die private Dateiliste beschränkt die Übertragung auf den exportierten Umfang. Es gibt kein `--delete`, keine Übernahme von Eigentümern/Gruppen und kein Kopieren von Symlinks. `--ignore-existing` schützt vorhandene Zieldateien vor Überschreiben. Eigentümer und Leserechte für den Ziel-Webserver verantwortet die Administration.

Die CLI startet keine SSH-Verbindung, speichert keine Zugangsdaten und führt rsync nicht aus. Die Quelldateien müssen aus dem unveränderten Snapshot stammen. Nachträgliche Änderungen werden durch die abschließende Prüfung erkannt, nicht automatisch korrigiert.

## 4. Prüfen und abschließen

```sh
wp rrze-migration media verify RUN_ID --run-dir=/srv/private/rrze-migrations
wp rrze-migration status RUN_ID --run-dir=/srv/private/rrze-migrations --format=json
```

Die Prüfung kontrolliert:

- ursprüngliches Paket und unveränderte Transferdateien;
- Zielinstallation, Netzwerk, Run-Zugehörigkeit der neu angelegten Website und unveränderten Medienort;
- relative Dateipfade, reguläre Dateien ohne verlinkte Eltern, Dateigrößen und SHA-256-Prüfsummen;
- fehlende und zusätzlich kopierte Dateien;
- Attachment-Dateien, ihre effektiven URLs und registrierte Bildgrößen.

Erst nach erfolgreicher Prüfung steht der Lauf auf `completed` und `uploads.verified` auf `true`. Die Prüfung verändert keine Website-Daten. Sie kann wiederholt werden; ein fehlgeschlagener erneuter Check setzt den Lauf auf `media_pending`. Der Nachweis gilt für den protokollierten Prüfzeitpunkt. Ausgeschlossene Verzeichnisse und zugehörige Attachments werden nicht als geprüft ausgegeben. Normale redaktionelle Änderungen erst nach der Abnahme wieder freigeben.

Bei einem leeren Medienmanifest kann die Prüfung direkt ohne Dateitransfer erfolgen. Bei fehlenden Dateien den Transfer wiederholen. Bei beschädigten vorhandenen Dateien zunächst deren Ursache und Zugehörigkeit zum neuen Ziel prüfen und nur diese Dateien administrativ reparieren. Der vorgeschlagene rsync-Aufruf überschreibt sie auch beim Wiederholen nicht. Anschließend `media verify` erneut ausführen. Die Website für diesen Schritt nicht löschen und den Datenimport nicht wiederholen.

## Geschützte Medien mit rrze-ac

Wenn das Manifest Dateien unter `_protected` bzw. dem auf der Quelle konfigurierten rrze-ac-Schutzverzeichnis enthält, muss das Ziel vor der Übertragung denselben Schutz gewährleisten. rrze-ac und Webserver-Regeln zuerst einrichten. Die technische Prüfung verlangt den passenden Schutzverzeichnisnamen und die konfigurierte rrze-ac-Rewrite-Freigabe.

Die Administration testet zusätzlich mindestens eine geschützte Datei über ihre tatsächliche Ziel-URL: Zugriff ohne Berechtigung muss verweigert werden, Zugriff mit der vorgesehenen Berechtigung muss funktionieren. Erst nach diesen Tests:

```sh
wp rrze-migration media verify RUN_ID --access-checked
```

Der Schalter dokumentiert eine **administrative Bestätigung**, keinen automatisch durchgeführten HTTP-Test. Eine alte positive rrze-ac-Konfigurationsmarkierung oder passende Dateiprüfsummen ersetzen diesen Test nicht. Andere Schutzsysteme und Offload/CDN-Speicher benötigen eigene Unterstützung; sie werden nicht als automatisch geprüft ausgegeben.
