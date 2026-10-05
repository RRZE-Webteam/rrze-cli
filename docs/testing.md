# Migrationstests

Arbeitspaket 2 stellt PHPUnit, synthetische Testdaten, einen vollständigen Export und Import sowie eine CI-Konfiguration bereit. Die Tests schaffen die Grundlage für die Sicherheitskorrekturen der folgenden Arbeitspakete. Sie bestätigen noch nicht sämtliche Anforderungen aus [Arbeitspaket 1](migration-scope.md).

## Installation und schnelle Tests

Voraussetzungen sind PHP ab 8.2 mit den üblichen PHPUnit-Erweiterungen sowie Composer. Die Testwerkzeuge werden getrennt von den eingecheckten Laufzeitbibliotheken installiert:

```sh
composer test:install
composer test:unit
```

`tests/composer.lock` legt die Versionen der Testwerkzeuge fest. Das bestehende `vendor`-Verzeichnis und die dort verwendeten Versionen bleiben unverändert. Die Unit-Tests laden weder WordPress noch eine Datenbankkonfiguration. `composer test` führt ebenfalls nur die Unit-Tests aus.

## Integrationstests mit der lokalen Installation

Verwendet werden das vorhandene WordPress-Core, WP-CLI und der lokale MySQL-Server, beispielsweise MAMP. Die Tests booten die bestehende WordPress-Website nicht. WP-CLI liest mit `config get` lediglich deren Datenbank-Zugangskonstanten. Zugangsdaten werden intern verarbeitet und nicht in Ausgaben oder Testberichte geschrieben.

Der Testlauf erstellt zwei temporäre WordPress-Kopien mit eigenen Datenbanken. Als Datenbankserver sind ausschließlich `localhost` und `127.0.0.1` zugelassen. Der Datenbankbenutzer benötigt Rechte zum Anlegen und Löschen eigener Datenbanken. Die vorhandene WordPress-Datenbank wird weder als Testziel verwendet noch zurückgesetzt.

```sh
composer test:integration
```

Standardmäßig wird WordPress drei Verzeichnisebenen oberhalb dieses Plugins gesucht; `wp`, `mysql` und `mysqldump` werden aus `PATH` verwendet. Diese Variablen erlauben eine abweichende lokale Konfiguration:

| Variable | Bedeutung |
| --- | --- |
| `RRZE_TEST_WP_ROOT` | Absoluter Pfad zur vorhandenen WordPress-Installation mit Core und `wp-config.php`. |
| `RRZE_TEST_WP_CLI` | Pfad zur vorhandenen WP-CLI-PHAR oder ihrem PHP-Einstiegspunkt. |
| `RRZE_TEST_PHP_BINARY` | PHP-Binary für die WP-CLI-Unterprozesse; standardmäßig dieselbe PHP-Version wie PHPUnit. |
| `RRZE_TEST_MYSQL_BIN` | Verzeichnis mit `mysql` und `mysqldump`, passend zum lokalen Server. |
| `RRZE_TEST_DB_HOST`, `RRZE_TEST_DB_USER`, `RRZE_TEST_DB_PASSWORD` | Optionale lokale Testverbindung mit CREATE-/DROP-DATABASE-Rechten. Nicht gesetzte Werte werden aus der lokalen WordPress-Konfiguration gelesen. Es gibt keine Option zur Verwendung einer vorhandenen Datenbank. |
| `RRZE_TEST_DB_CONFIG` | Optionaler Pfad zu einer privaten JSON-Datei mit `DB_HOST`, `DB_USER` und `DB_PASSWORD`. Standardmäßig wird `tests/.local.json` verwendet, sofern vorhanden. Umgebungsvariablen haben Vorrang. |

Beispiel für die hier verwendete MAMP-Installation:

```sh
RRZE_TEST_MYSQL_BIN=/Applications/MAMP/Library/bin/mysql57/bin \
  /Applications/MAMP/bin/php/php8.3.30/bin/php tests/vendor/bin/phpunit \
  --configuration tests/phpunit-integration.xml
```

Lokal läuft MySQL 5.7.44; deshalb verwendet dieses Beispiel die MAMP-Clients unter `mysql57`. Bei einem MySQL-8.0-Server ist stattdessen `mysql80` zu verwenden. PHP 8.3 vermeidet hier zusätzliche Deprecation-Ausgaben aus WP-CLI 2.12 unter PHP 8.5. Die Prüfung der Datenbankverbindung darf nicht durch das Unterdrücken von Warnungen oder das Ausweichen auf die bestehende Datenbank ersetzt werden. Extern berechnete Datenbankkonstanten, die `wp config get` nicht lesen kann, sind derzeit nicht unterstützt.

Hat der normale WordPress-Datenbankbenutzer keine Rechte zur Neuanlage von Datenbanken, wird vor der Testeinrichtung abgebrochen. Für diesen Fall wird eine gesonderte lokale Testverbindung über die genannten Umgebungsvariablen oder eine private JSON-Datei bereitgestellt. `tests/local.example.json` kann als Vorlage für `tests/.local.json` dienen. Die private Datei ist von Git ausgeschlossen und sollte nur für den eigenen Benutzer lesbar sein (`chmod 600 tests/.local.json`). Nicht angegebene Einstellungen werden weiterhin aus der lokalen WordPress-Konfiguration gelesen. Echte Zugangsdaten gehören weder in die Dokumentation noch in eingecheckte Dateien.

### Eingerichteter lokaler MAMP-Zugang

Für diese Entwicklungsinstallation wurde `rrze_cli_test`@`localhost` mit einem zufälligen Passwort eingerichtet. Die private Konfiguration verwendet den Socket `/Applications/MAMP/tmp/mysql/mysql.sock`. Der administrative MAMP-Zugang wurde nur zur Einrichtung verwendet und ist nicht in den Testinstallationen hinterlegt.

Der Testbenutzer besitzt Datenbankrechte ausschließlich für Namen mit dem wörtlichen Präfix `rrze_cli_test_`, ohne globale Rechte oder Berechtigung zur Rechtevergabe. Unter dem hier eingesetzten MySQL 5.7 lautet die Reichweite des Grants `` `rrze\_cli\_test\_%`.* ``: Die Unterstriche sind maskiert, nur das abschließende Prozentzeichen ist ein Platzhalter. Das Anlegen und Löschen einer eigenen Probedatenbank sowie die Zugriffsverweigerung auf die MySQL-Systemdatenbank wurden bei der Einrichtung geprüft. Der bestehende WordPress-Datenbankbenutzer wurde nicht verändert.

Diese Präfixberechtigung ist versionsabhängig: [MySQL 8 mit aktiviertem `partial_revokes`](https://dev.mysql.com/doc/refman/8.0/en/partial-revokes.html) behandelt solche Muster anders. Bei einer anderen Serverversion muss die Rechtevergabe erneut geprüft werden; ein Fehlschlag rechtfertigt keine Erweiterung auf sämtliche Datenbanken.

## Isolation und Aufräumen

Jeder Lauf bekommt einen zufälligen Bezeichner, ein privates temporäres Verzeichnis mit Eigentumsmarker und zwei neue Datenbanken mit dem Präfix `rrze_cli_test_`. Ein bereits vorhandener Datenbankname wird nicht übernommen. Bereinigt werden ausschließlich Datenbanken, deren Anlage der aktuelle Lauf bestätigt hat, und Dateien unter seinem markierten Verzeichnis. Auch bei Testfehlern wird aufgeräumt.

In die Kopien gelangen nur WordPress-Core, das zu prüfende rrze-cli mit seinen Laufzeitbibliotheken, ein minimales Testtheme und ein Testfilter zum Unterbinden von E-Mail-Versand. Andere lokale Plugins, Themes, Uploads, Datenbanken und die ursprüngliche `wp-config.php` werden nicht kopiert. Die Kopien erhalten eine neue Konfiguration; Cron, automatische Core-Updates und externe WordPress-HTTP-Anfragen sind dort abgeschaltet. Für WP-CLI werden eine eigene Konfiguration und ein eigenes Paketverzeichnis verwendet.

Ein hart beendeter Prozess, etwa durch `SIGKILL`, kann keine Abschlussbereinigung ausführen. In diesem Fall enthält das private Verzeichnis `rrze-cli-tests-…` die Datei `databases.json` mit den Namen dieses Laufs. Reste werden nach Prüfung von Eigentumsmarker und Namen manuell entfernt; es gibt keine pauschale Bereinigung aller Datenbanken mit einem Namenspräfix. Temporäre Konfigurationen enthalten lokale Zugangsdaten und dürfen nicht veröffentlicht werden.

## Testdaten und Prüfungen

Die Quelldatenbank verwendet `src_`, die Zieldatenbank `dst_`. Beide enthalten eine eigene Hauptsite. In der Quelle wird eine Untersite befüllt, im Ziel eine unabhängige Kontrollwebsite. Der Import muss eine zusätzliche Website anlegen.

Die Fixture enthält veröffentlichte und unveröffentlichte Inhalte, eine Seitenhierarchie, eine Kategorie, verschachtelte serialisierte Metadaten und Optionen, eine Bilddatei samt Beitragsbild-Zuordnung sowie eine benutzerdefinierte Tabelle. Die fiktiven SSO-Kennungen `sso0001` und `sso0002` benutzen Adressen unter `company.example`. Quell-ID 2 muss einer vorhandenen Ziel-ID 4 zugeordnet werden; für Quell-ID 3 entsteht im bisherigen Importablauf Ziel-ID 5. Dasselbe Zahlenpaar bezeichnet in Quelle und Ziel absichtlich unterschiedliche Personen.

Der vollständige Test prüft Inhalte, URL-Ersetzungen, Beziehungen, Dateiinhalte, eigene Tabellen und unterstützte Benutzerreferenzen. Er vergleicht außerdem die Quellwebsite, sämtliche Tabellen und Dateien der Kontrollwebsite sowie vorhandene globale Benutzer vor und nach dem Lauf. Bei vorhandenen Benutzern sind nur die Mitgliedschaftsschlüssel der neu angelegten Website ausgenommen. Ein eigener Test verändert die Kontrollwebsite absichtlich und prüft, dass der Vergleich diese Änderung erkennt.

Vor den Vergleichen wird jede befüllte Testwebsite einmal vollständig über WP-CLI geladen. Dadurch sind WordPress' anfängliche Theme-, Widget-, Rewrite- und Cron-Einträge eingerichtet. Erst danach wird der Ausgangszustand erfasst; keine dieser Optionen wird vom Vergleich ausgenommen. Bei Unterschieden enthält der Bericht zusätzlich die Namen und Hashes der geänderten Optionen. Der vollständige Importtest verwendet bewusst eine Ziel-URL mit abschließendem Schrägstrich und erwartet korrekt ersetzte Links auch in serialisierten Werten.

Ein weiterer Test importiert auf eine bereits belegte Zieladresse und erwartet einen Fehlerstatus ohne Änderung der Kontrollwebsite, ihrer Benutzer oder der Site-Liste. Dies deckt einen Teil der Zielregel ab; eine vollständige Prüfung aller Site-Status und temporären Dateien folgt mit den Sicherheitskorrekturen.

`tests/fixtures/user-conflicts.json` enthält zusätzliche synthetische Fälle für abweichende Firmenadressen, gleiche E-Mail bei unterschiedlichen Kennungen und widersprüchliche Login-/E-Mail-Treffer. Diese Konfliktfälle sind vorbereitet, aber noch keine bestandenen Sicherheitstests. Eine echte Anmeldung über rrze-sso oder einen Identity Provider findet in Paket 2 nicht statt.

## Coverage und CI

```sh
composer test:coverage
composer test:all
```

Der Coverage-Lauf benötigt PCOV oder Xdebug im Coverage-Modus. Er schreibt HTML nach `tests/.artifacts/coverage/` und Clover-XML nach `tests/.artifacts/coverage.xml`. Erfasst werden die Unit-Tests; PHP-Code in den WP-CLI-Unterprozessen des Integrationstests ist darin nicht enthalten. Die anfänglich geringe Prozentzahl ist keine Aussage über eine vollständige Sicherheitsabdeckung. Es gibt zunächst keine künstliche Coverage-Schwelle.

Die GitHub-Actions-Konfiguration führt die Tests mit PHP 8.2, 8.3 und 8.4, WordPress 6.8.3 und MySQL 8.0.43 aus. CI erstellt lediglich eine Core-Vorlage mit Zugang zu ihrem eigenen MySQL-Service; die Testdatenbanken werden auch dort vom Testlauf neu angelegt. Berichte und Ausgaben mit ausschließlich synthetischen Testdaten werden für sieben Tage als Artefakte gespeichert. Die zusätzliche lokale Core-Version ergibt sich aus der verwendeten Installation; daraus folgt keine Freigabe aller Kombinationen für Production.

## Lokal verifizierter Stand

Am 5. Oktober 2026 erfolgreich ausgeführt:

- 13 Unit-Tests mit 23 Assertions unter PHP 8.3.30 und PHP 8.5.10.
- 3 Integrationstests mit 29 Assertions unter PHP 8.3.30, WP-CLI 2.12.0, WordPress 7.1.2 und MAMP MySQL 5.7.44 mit eingeschränktem Testbenutzer.
- Vollständiger Export und Import einschließlich Medien, serialisierter Daten, eigener Tabelle und Benutzerzuordnung; Vergleich von Quelle, Kontrollwebsite und bestehenden Benutzern; Ablehnung einer belegten Zieladresse.
- Bereinigung der vom Testlauf angelegten Datenbanken und Dateien; das Entfernen des temporären Verzeichnisses wird zusätzlich als Assertion geprüft.
- Unit-Coverage mit PCOV: 52 von 1213 Zeilen (4,29 %). Der HTML-Bericht liegt unter `tests/.artifacts/coverage/index.html`, der Integrationsbericht unter `tests/.artifacts/integration/junit.xml`.

Der Integrationstest deckte einen Fehler bei Ziel-URLs mit abschließendem Schrägstrich auf: Links und serialisierte Werte erhielten einen doppelten Schrägstrich im Pfad. `Utils::parse_url_for_search_replace()` normalisiert nun den abschließenden Schrägstrich beider Basis-URLs. Der unveränderte Importtest prüft diese Korrektur.

Damit ist die lokale Testgrundlage aus Paket 2 ausführbar und geprüft. Die GitHub-CI ist konfiguriert, ein tatsächlicher Lauf auf GitHub steht noch aus. Die vollständige Sicherheitsabdeckung und eine Betriebsfreigabe sind weiterhin Gegenstand der folgenden Arbeitspakete.

## Nächste Erweiterungen

Mit Paket 3 kommen Regressionstests für Fehlerweitergabe, Single-Site-Ablehnung, Hauptsite-Tabellenabgrenzung, `--custom-tables`, Benutzerkonflikte und sensible Paketfelder hinzu. Abbruch, Wiederholung, konkurrierende Läufe, Ressourcenlimits und Wiederherstellung werden mit den entsprechenden Implementierungen ergänzt. Bekannte Fehler werden nicht durch Tests als gewünschtes Verhalten festgeschrieben.
