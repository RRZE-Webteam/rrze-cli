# Migrationsläufe und Wiederherstellung

Ein Import legt eine neue Website an. Paket 5 protokolliert die Ausführung und bewahrt das verwendete Paket für einen erneuten Import auf. Nach einem Fehler wird keine Website automatisch gelöscht, kein Einzelschritt wiederholt und kein globales Konto entfernt. Die Wiederherstellung erfolgt als neuer Import nach manueller Prüfung und Löschung einer unvollständigen Zielwebsite.

## Privaten Speicherort einrichten

ZIP-Exporte und echte Importe verwenden gemeinsam `--run-dir` beziehungsweise die Konstante `RRZE_MIGRATION_RUN_DIR` in der jeweiligen Installation als privates Stammverzeichnis. Der absolute Pfad muss außerhalb der WordPress- und Content-Verzeichnisse liegen. Die Administration muss zusätzlich ausschließen, dass ein anderer Webserver oder eine Freigabe dieses Verzeichnis veröffentlicht. Sein Elternverzeichnis muss existieren; das Laufverzeichnis wird bei Bedarf mit Modus `0700` angelegt. Bestehende öffentliche Verzeichnisse werden abgelehnt und nicht automatisch umkonfiguriert.

```sh
wp rrze-migration import all incoming/website.zip \
  --new_url=https://target.example.test/new-site/ \
  --run-dir=/srv/private/rrze-migrations
```

Alternativ:

```php
define('RRZE_MIGRATION_RUN_DIR', '/srv/private/rrze-migrations');
```

ZIP-Exporte landen in neuen Unterordnern `export-<UTC-Zeitstempel>-site-<ID>-<Zufallskennung>`. Importläufe verwenden weiterhin eigene Unterordner mit ihrer Laufkennung; bestehende Journale bleiben am bisherigen Ort lesbar. Relative Importpfade beziehen sich auf dieses Stammverzeichnis. Ein Dry-run mit absolutem privaten Eingabepfad benötigt keine konfigurierte Ablage; bei einem relativen Pfad wird sie zum Auffinden des Pakets benötigt. Die Vorschau legt weder das Stammverzeichnis noch Laufdaten an.

Eingehende Pakete müssen bereits außerhalb von WordPress und `wp-content` liegen. Für den Transfer von einem anderen Server kann beispielsweise ein eigener privater Unterordner `incoming` vorbereitet werden. Eine bisher im Webroot liegende ZIP-Datei zuerst selbst in private Ablage verschieben. Der Import kopiert das Paket und lässt die Eingabedatei unverändert. Exporte werden wie Importläufe nicht automatisch nach einer Frist gelöscht.

Pro Lauf entsteht ein Verzeichnis mit einer zufälligen Kennung. Es enthält `package.zip`, `run.json` und `active.lock`. Dateien erhalten Modus `0600`. Das Paket wird vor der Prüfung privat kopiert; Prüfung und Ausführung verwenden diese Kopie. Eine spätere Änderung oder Löschung der ursprünglich übergebenen Datei verändert die aufbewahrte Kopie nicht. Der abschließende Status prüft deren SHA-256-Wert.

Die Paketkopie enthält die migrierten Inhalte und ist entsprechend vertraulich zu behandeln. Sie ist weder eine Sicherung des gesamten Zielnetzwerks noch ein Ersatz für die unabhängige Sicherung einer vor dem Import manuell gelöschten Website. Aufbewahrung, externe Sicherung, Verschlüsselung und kontrollierte Entfernung alter Laufverzeichnisse liegen bei der Administration. Temporäre Verzeichnisse mit automatischer Bereinigung eignen sich nicht für dauerhafte Laufdaten.

## Schritte und Zustände lesen

Der Import nennt seine Laufkennung auf der Konsole. Der Statusbefehl liest das Protokoll und verändert keine Website:

```sh
wp rrze-migration status RUN_ID --run-dir=/srv/private/rrze-migrations
wp rrze-migration status RUN_ID --run-dir=/srv/private/rrze-migrations --format=json
```

Ein erfolgreicher Exitcode des Statusbefehls bestätigt das Lesen des Berichts, nicht den Erfolg des Imports. Maßgeblich sind der gemeldete Laufzustand, die Schrittzustände und die Paketprüfsumme.

Der Ablauf umfasst Paketvorbereitung, Sperre, erneute Vorprüfung, Site-Anlage, Tabellenimport, URL-Ersetzung, Site-Konfiguration, Benutzerübernahme, Benutzerreferenzen, Medien, Rewrite-Regeln, Ergebnisprüfung und Bereinigung. Vor einem Schritt wird `started`, nach erfolgreichem Abschluss `completed` gespeichert. Checkpoints werden über eine private neue Datei, Flush/Fsync und anschließendes Umbenennen veröffentlicht. Das ist keine Transaktion über Datenbank und Dateisystem und keine Garantie gegen sämtliche Hardware- oder Dateisystemausfälle.

Das Protokoll enthält IDs, Zielressourcen, Benutzer-ID-Zuordnungen, Dateizähler und Prüfsummen. Passwörter, Token, Benutzerprofile, SQL-Inhalte und rohe Fehlermeldungen werden nicht hineingeschrieben. `pending_user` bedeutet, dass die Kontooperation begonnen wurde, aber noch keine sichere abschließende Zuordnung gespeichert ist. Ein Dateizähler kann nach einem harten Abbruch hinter der tatsächlichen Zahl übertragener Dateien liegen.

| Zustand | Bedeutung |
| --- | --- |
| `active` | Der Lauf hält seine Dateisperre. Nicht aufräumen oder parallel wiederherstellen. |
| `completed` | Der gewählte Importumfang einschließlich Ergebnisprüfung und Arbeitsverzeichnis-Bereinigung wurde erfolgreich protokolliert. Bei `--skip-uploads` bleiben die manuellen Medienarbeiten offen. |
| `failed` | Ein behandelter Fehler hat den Ablauf gestoppt. `failure_step` nennt den zuletzt betroffenen Schritt; bereits ausgeführte Änderungen bleiben bestehen. |
| `interrupted` | Ein Abbruch wurde erkannt und protokolliert. Bereits ausgeführte Änderungen bleiben bestehen. |
| `interrupted_or_unfinished` | Das Protokoll meldet noch einen laufenden Vorgang, dessen Dateisperre aber nicht mehr gehalten wird. Der letzte Schritt kann teilweise oder vollständig ausgeführt sein. Es erfolgt keine automatische Schlussfolgerung aus einem verschwundenen Prozess. |

Die Installationssperre umfasst alle rrze-cli-Importe derselben Datenbank und desselben Basispräfixes, auch bei unterschiedlichen Zieladressen. Sie schützt gemeinsam genutzte Benutzer und die Site-ID-Vergabe. Sie blockiert keine normalen WordPress-Anfragen oder fremden Administrationswerkzeuge. Eine Wiederverbindung zur Datenbank ersetzt die Sperre nicht: Wird sie verloren, stoppt der nächste geprüfte Schritt.

Bei ausdrücklich übersprungenen Uploads enthält der Medien-Schritt `skipped` mit Grund `manual_transfer`. Das Journal bewahrt `uploads.skipped: true`, `uploads.transfer: false`, `uploads.verified: false` und `manual_transfer_required: true`; es nennt keinen aufgelösten Upload-Zielpfad. Status und Abschlussmeldung weisen auf den separaten Transfer, die Konfiguration von Pfaden/URLs und die ausstehende Medienprüfung hin. `completed` bestätigt in diesem Fall ausschließlich den gewählten Datenimport. Auch nach einem externen `rsync` aktualisiert der Statusbefehl den Mediennachweis nicht automatisch. Bei einem erneuten Import muss `--skip-uploads` wieder ausdrücklich gewählt werden.

## Einen Abbruch behandeln

1. Status und Protokoll lesen. Laufkennung, Zieladresse, Site-ID, Tabellen und Upload-Verzeichnis festhalten. Bei `started` nicht annehmen, dass noch nichts geschrieben wurde.
2. Vor Bereinigungen sicherstellen, dass der Importprozess und alle von ihm gestarteten WP-CLI-/Datenbankprozesse beendet sind. Ein hart beendeter Elternprozess kann laufende Kindprozesse zurücklassen. Eine freigegebene Dateisperre beweist nicht, dass sämtliche Kindprozesse beendet sind.
3. Die unvollständige neue Website und ihre Ressourcen prüfen. Falls ein neuer Versuch erfolgen soll, die Website manuell in Network Admin löschen. Tabellen von Erweiterungen oder Dateien können dabei zurückbleiben; diese anhand des Protokolls prüfen. Keine pauschalen Präfix-Löschungen durchführen.
4. Globale WordPress-Benutzer erhalten. Auch ein während des abgebrochenen Imports angelegtes Konto kann inzwischen anderweitig verwendet werden. Beim erneuten Import werden Kennung und Firmenadresse erneut geprüft und passende Konten wiederverwendet.
5. Nach bestätigtem Prozessende das im Protokoll genannte private Arbeitsverzeichnis bei Bedarf gezielt bereinigen. Ein harter Abbruch kann es zurücklassen. Die Paketkopie und das Laufprotokoll für die Wiederherstellung behalten.
6. Prüfsumme der aufbewahrten Paketkopie mit dem Statusbefehl prüfen. Danach den Dry-run auf genau diesem Paket ausführen, einschließlich der ursprünglichen `--uid_fields`. Fehlende oder widersprüchliche Ressourcen müssen vor einem neuen Import geklärt werden.
7. Den Import mit denselben fachlichen Parametern neu starten. Er erhält eine neue Laufkennung und eine neue Site-ID. Anschließend Inhalte, Medien, Benutzerrollen und SSO-Anmeldung abnehmen.

```sh
wp rrze-migration import all /srv/private/rrze-migrations/RUN_ID/package.zip \
  --new_url=https://target.example.test/new-site/ \
  --uid_fields=_fixture_user --dry-run

wp rrze-migration import all /srv/private/rrze-migrations/RUN_ID/package.zip \
  --new_url=https://target.example.test/new-site/ \
  --uid_fields=_fixture_user --run-dir=/srv/private/rrze-migrations
```

`_fixture_user` ist ein Testbeispiel und durch tatsächlich verwendete numerische Benutzerreferenzen zu ersetzen oder wegzulassen. Ein vorhandenes Ziel blockiert auch diesen Wiederherstellungsweg. Es gibt keinen `resume`-, `force`- oder Überschreibschalter.

## Grenzen und Betriebsabnahme

Bei verfügbarem PCNTL fordert `SIGINT` oder `SIGTERM` einen Abbruch an einer geprüften Grenze an. Ein laufender Datenbankbefehl muss zunächst zurückkehren; der Vorgang wird nicht als atomar rückgängig gemacht. Ohne PCNTL oder bei `SIGKILL`, Stromausfall und ähnlichen Abbrüchen bleibt gegebenenfalls nur der letzte Checkpoint.

Die Ergebnisprüfung kontrolliert Zielzugehörigkeit, Tabellen, URLs, Benutzerrollen und -referenzen sowie die Hashes der automatisch übertragenen Medien. Globale Daten bereits vorhandener beteiligter Benutzer dürfen sich gegenüber dem Ausgangszustand ausschließlich um die Mitgliedschaft der neuen Website unterscheiden. Ändert ein anderer Prozess währenddessen beispielsweise deren Profil oder Sitzungen, kann die konservative Prüfung ebenfalls abbrechen. Solche Unterschiede werden nicht automatisch zurückgeschrieben.

Die ursprüngliche Website nach einer vorherigen manuellen Löschung wiederherzustellen, globale Fremdänderungen rückgängig zu machen oder beliebiges fremdes SQL auszuführen, ist nicht durch dieses Protokoll abgesichert. Dafür bleiben unabhängige Sicherungen, ein abgestimmtes Wartungsfenster und eine gesonderte Betriebsabnahme erforderlich. Globale Benutzer- oder Netzwerktabellen dürfen nicht pauschal über eine weiterbetriebene Multisite zurückgespielt werden.
