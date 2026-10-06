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

Der Testlauf erstellt zwei temporäre Multisite-Kopien und eine Single-Site-Kopie mit eigenen Datenbanken. Als Datenbankserver sind ausschließlich `localhost` und `127.0.0.1` zugelassen. Der Datenbankbenutzer benötigt Rechte zum Anlegen und Löschen eigener Datenbanken. Die vorhandene WordPress-Datenbank wird weder als Testziel verwendet noch zurückgesetzt.

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

Für WP-CLI wird der erste ausführbare PHP-Einstiegspunkt beziehungsweise die erste PHAR in `PATH` verwendet. Shell-Wrapper wie das von Composer vorangestellte `vendor/bin/wp` werden übersprungen, da die Sandbox WP-CLI ausdrücklich mit der gewählten PHP-Version startet. Wenn nur Shell-Wrapper installiert sind, muss `RRZE_TEST_WP_CLI` auf die eigentliche ausführbare PHAR oder PHP-Datei zeigen. Ein explizit angegebener Shell-Wrapper wird mit einem Konfigurationsfehler abgelehnt.

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

Jeder Lauf bekommt einen zufälligen Bezeichner, ein privates temporäres Verzeichnis mit Eigentumsmarker und drei neue Datenbanken mit dem Präfix `rrze_cli_test_`. Ein bereits vorhandener Datenbankname wird nicht übernommen. Bereinigt werden ausschließlich Datenbanken, deren Anlage der aktuelle Lauf bestätigt hat, und Dateien unter seinem markierten Verzeichnis. Auch bei Testfehlern wird aufgeräumt.

In die Kopien gelangen nur WordPress-Core, das zu prüfende rrze-cli mit seinen Laufzeitbibliotheken, ein minimales Testtheme und ein Testfilter zum Unterbinden von E-Mail-Versand. Andere lokale Plugins, Themes, Uploads, Datenbanken und die ursprüngliche `wp-config.php` werden nicht kopiert. Die Kopien erhalten eine neue Konfiguration; Cron, automatische Core-Updates und externe WordPress-HTTP-Anfragen sind dort abgeschaltet. Für WP-CLI werden eine eigene Konfiguration und ein eigenes Paketverzeichnis verwendet.

Ein hart beendeter Prozess, etwa durch `SIGKILL`, kann keine Abschlussbereinigung ausführen. In diesem Fall enthält das private Verzeichnis `rrze-cli-tests-…` die Datei `databases.json` mit den Namen dieses Laufs. Reste werden nach Prüfung von Eigentumsmarker und Namen manuell entfernt; es gibt keine pauschale Bereinigung aller Datenbanken mit einem Namenspräfix. Temporäre Konfigurationen enthalten lokale Zugangsdaten und dürfen nicht veröffentlicht werden.

## Testdaten und Prüfungen

Die Quelldatenbank verwendet `src_`, die Zieldatenbank `dst_`. Beide enthalten eine eigene Hauptsite. In der Quelle wird eine Untersite befüllt, im Ziel eine unabhängige Kontrollwebsite. Der Import muss eine zusätzliche Website anlegen.

Die Fixture enthält veröffentlichte und unveröffentlichte Inhalte, eine Seitenhierarchie, eine Kategorie, verschachtelte serialisierte Metadaten und Optionen, eine Bilddatei samt Beitragsbild-Zuordnung sowie eine benutzerdefinierte Tabelle. Die fiktiven SSO-Kennungen `sso0001` und `sso0002` benutzen Adressen unter `company.example`. Quell-ID 2 muss einer vorhandenen Ziel-ID 4 zugeordnet werden; für Quell-ID 3 entsteht im bisherigen Importablauf Ziel-ID 5. Dasselbe Zahlenpaar bezeichnet in Quelle und Ziel absichtlich unterschiedliche Personen.

Der vollständige Test prüft Inhalte, URL-Ersetzungen, Beziehungen, Dateiinhalte, eigene Tabellen und unterstützte Benutzerreferenzen. Er vergleicht außerdem die Quellwebsite, sämtliche Tabellen und Dateien der Kontrollwebsite sowie vorhandene globale Benutzer vor und nach dem Lauf. Bei vorhandenen Benutzern sind nur die Mitgliedschaftsschlüssel der neu angelegten Website ausgenommen. Ein eigener Test verändert die Kontrollwebsite absichtlich und prüft, dass der Vergleich diese Änderung erkennt.

Vor den Vergleichen wird jede befüllte Testwebsite einmal vollständig über WP-CLI geladen. Dadurch sind WordPress' anfängliche Theme-, Widget-, Rewrite- und Cron-Einträge eingerichtet. Erst danach wird der Ausgangszustand erfasst; keine dieser Optionen wird vom Vergleich ausgenommen. Bei Unterschieden enthält der Bericht zusätzlich die Namen und Hashes der geänderten Optionen. Der vollständige Importtest verwendet bewusst eine Ziel-URL mit abschließendem Schrägstrich und erwartet korrekt ersetzte Links auch in serialisierten Werten.

Die Zielprüfungen testen außerdem Single-Site-Ablehnung, belegte Adressen mit anderem Schema, anderer Host-Schreibweise und fehlendem Schrägstrich sowie archivierte, als gelöscht markierte, Spam-, nichtöffentliche und als mature markierte Websites. Diese Fälle müssen vor Änderungen an der Zieldatenbank scheitern. Auch die ehemaligen direkten Änderungsbefehle werden auf Ablehnung geprüft.

`tests/fixtures/user-conflicts.json` enthält zusätzliche synthetische Fälle für abweichende Firmenadressen, gleiche E-Mail bei unterschiedlichen Kennungen und widersprüchliche Login-/E-Mail-Treffer. Diese Konfliktfälle werden sowohl in Unit-Tests als auch mit veränderten Testpaketen im Integrationstest durchgespielt; die gesamte Zieldatenbank muss bei ihrer Ablehnung unverändert bleiben. Eine echte Anmeldung über rrze-sso oder einen Identity Provider findet in Paket 2 nicht statt.

## Coverage und CI

```sh
composer test:coverage
composer test:all
```

Der Coverage-Lauf benötigt PCOV oder Xdebug im Coverage-Modus. Er schreibt HTML nach `tests/.artifacts/coverage/` und Clover-XML nach `tests/.artifacts/coverage.xml`. Erfasst werden die Unit-Tests; PHP-Code in den WP-CLI-Unterprozessen des Integrationstests ist darin nicht enthalten. Die anfänglich geringe Prozentzahl ist keine Aussage über eine vollständige Sicherheitsabdeckung. Es gibt zunächst keine künstliche Coverage-Schwelle.

Die GitHub-Actions-Konfiguration führt die Tests mit PHP 8.2, 8.3 und 8.4, WordPress 6.8.3 und MySQL 8.0.43 aus. CI erstellt lediglich eine Core-Vorlage mit Zugang zu ihrem eigenen MySQL-Service; die Testdatenbanken werden auch dort vom Testlauf neu angelegt. Berichte und Ausgaben mit ausschließlich synthetischen Testdaten werden für sieben Tage als Artefakte gespeichert. Die zusätzliche lokale Core-Version ergibt sich aus der verwendeten Installation; daraus folgt keine Freigabe aller Kombinationen für Production.

## Lokal verifizierter Stand von Paket 2

Am 5. Oktober 2026 erfolgreich ausgeführt:

- 13 Unit-Tests mit 23 Assertions unter PHP 8.3.30 und PHP 8.5.10.
- 3 Integrationstests mit 29 Assertions unter PHP 8.3.30, WP-CLI 2.12.0, WordPress 7.1.2 und MAMP MySQL 5.7.44 mit eingeschränktem Testbenutzer.
- Vollständiger Export und Import einschließlich Medien, serialisierter Daten, eigener Tabelle und Benutzerzuordnung; Vergleich von Quelle, Kontrollwebsite und bestehenden Benutzern; Ablehnung einer belegten Zieladresse.
- Bereinigung der vom Testlauf angelegten Datenbanken und Dateien; das Entfernen des temporären Verzeichnisses wird zusätzlich als Assertion geprüft.
- Unit-Coverage mit PCOV: 52 von 1213 Zeilen (4,29 %). Der HTML-Bericht liegt unter `tests/.artifacts/coverage/index.html`, der Integrationsbericht unter `tests/.artifacts/integration/junit.xml`.

Der Integrationstest deckte einen Fehler bei Ziel-URLs mit abschließendem Schrägstrich auf: Links und serialisierte Werte erhielten einen doppelten Schrägstrich im Pfad. `Utils::parse_url_for_search_replace()` normalisiert nun den abschließenden Schrägstrich beider Basis-URLs. Der unveränderte Importtest prüft diese Korrektur.

Damit ist die lokale Testgrundlage aus Paket 2 ausführbar und geprüft. Die GitHub-CI ist konfiguriert, ein tatsächlicher Lauf auf GitHub steht noch aus. Die vollständige Sicherheitsabdeckung und eine Betriebsfreigabe sind weiterhin Gegenstand der folgenden Arbeitspakete.

## Lokal verifizierter Stand von Paket 3

Am 5. Oktober 2026 mit der erweiterten Suite erfolgreich ausgeführt:

- 61 Unit-Tests mit 83 Assertions unter PHP 8.5.10, einschließlich der parallel ergänzten WP-CLI-Erkennung.
- 14 Integrationstests mit 184 Assertions unter PHP 8.3.30, WP-CLI 2.12.0, WordPress 7.1.2 und MAMP MySQL 5.7.44.
- Getrennte Quell-/Ziel-Multisites und eine echte Single-Site-Testinstallation. Der Datenbankbenutzer bleibt auf `rrze_cli_test_…` beschränkt.
- Hauptsite-Tabellenauswahl, zusätzliche eigene Tabellen, Dateinamen mit Leerzeichen und Schutz vorhandener Exportdateien.
- Benutzerkonflikte vor Site-Anlage, sensible Felder einschließlich Filterversuchen, unveränderte vorhandene Profile/Zugangsdaten und neue WordPress-Konten mit frischem Passwort auch bei Altpaketen.
- Fehlerhafter SQL-Import und gezielt fehlgeschlagene URL-Ersetzung: Fehlerstatus, keine Erfolgsmeldung, keine anschließende Benutzerübernahme, private Arbeitsdateien entfernt. Ein unvollständiger neuer Site-Eintrag bleibt bestehen und blockiert einen erneuten Import.
- Grundprüfungen für kaputte CSV/JSON-Dateien, globale SQL-Tabellen, Codeverzeichnisse und Pfadtraversal im Archiv.

Die Unterbefehle laufen jetzt grundsätzlich in Kindprozessen: Ein nativer Exit eines Datenbankbefehls kann den aufrufenden PHP-Prozess und dessen `finally`-Bereinigung nicht mehr überspringen. WordPress' einmalige Theme-/Widget-Initialisierung wird auch für die Single-Site-Fixture vor dem ersten Vergleich abgeschlossen.

Unit-Coverage: 141 von 894 Zeilen (15,77 %). Die neuen Regeln für Tabellenauswahl sind zu 91,67 %, die URL-Identitätsprüfung zu 100 % und die Benutzer-/CSV-Regeln einschließlich ihres WordPress-Adapters zu 74,47 % zeilenweise durch Unit-Tests erfasst. Der Integrationstest prüft zusätzlich die tatsächlichen WP-CLI-Abläufe; seine Kindprozesse sind weiterhin nicht im Coverage-Prozentwert enthalten. Die Prozentwerte allein belegen keine vollständige Sicherheitsabdeckung.

## Lokal verifizierter Stand von Paket 4

Am 6. Oktober 2026 erfolgreich ausgeführt:

- 105 Unit-Tests mit 144 Assertions unter PHP 8.5.10.
- 20 Integrationstests mit 283 Assertions unter PHP 8.3.30, WP-CLI 2.12.0, WordPress 7.1.2 und MAMP MySQL 5.7.44.
- Unit-Coverage mit PCOV: 304 von 1206 Zeilen (25,21 %). Die WP-CLI-Kindprozesse der Integrationstests sind darin weiterhin nicht enthalten.
- Syntaxprüfung aller 13 geänderten oder neuen PHP-Dateien sowie `git diff --check` ohne Fehler.
- Vollständiger Export/Import im neuen Paketformat, beide Dry-run-Ausgaben, unveränderte Kontrollwebsite und globale Bestandskonten sowie Bereinigung der eigenen Testdatenbanken und Arbeitsverzeichnisse.

`import all --dry-run` durchläuft dieselbe Paket-, Tabellen-, Benutzer- und Zielprüfung wie die Ausführung. Der Integrationstest liest den JSON-Plan direkt als JSON und vergleicht Tabellen- und Benutzerzuordnung mit dem anschließenden Import. Vor und nach beiden Dry-run-Ausgabeformaten werden sämtliche Zieltabellen und die Upload-Verzeichnisse verglichen. Die allgemeine Startausgabe des Plugins wird nicht mehr auf Standardausgabe geschrieben, damit maschinenlesbare Ergebnisse verwendbar bleiben.

Die neuen Tests decken Formatversionen und Prüfsummen, unerwartete und mehrdeutige Pfade, Links, Verschlüsselung, Größen- und Kompressionsgrenzen sowie Datei-, Speicher- und Rechteprüfungen ab. Veränderte CSV-/SQL-Testpakete erhalten gezielt ein aktualisiertes Manifest, damit die fachliche Vorprüfung zusätzlich zur Integritätsprüfung getestet wird. Eigene Beschädigungstests lassen die Prüfsummen unverändert und müssen daran scheitern.

Resttabellen, Upload-Verzeichnisse und alte Mitgliedschaftsschlüssel der nächsten Site-ID blockieren bereits die Vorprüfung. Ein zusätzlicher Test erzeugt eine Resttabelle zwischen der Site-Anlage und der WordPress-Initialisierung: Der Import muss vor dem Initialisieren abbrechen und den fremden Tabelleninhalt erhalten. Die nur teilweise angelegte Site wird weiterhin nicht automatisch gelöscht. Medien direkt im Upload-Hauptverzeichnis und ihre endgültigen Dateirechte erhalten einen eigenen Integrationstest.

Die Berechtigungsprüfung berücksichtigt explizite Schema-Grants einschließlich maskierter Unterstriche und MySQLs `partial_revokes`-Semantik; reine Rollen- oder Tabellen-Grants werden konservativ abgelehnt. Die hier simulierten eingeschränkten Rechte ergänzen den echten eingeschränkten MAMP-Testbenutzer. Produktionsrechte und Datenbankserver-Speicherplatz werden dadurch nicht als geprüft ausgegeben.

## Lokal verifizierter Stand von Paket 5

Am 6. Oktober 2026 erfolgreich ausgeführt:

- 115 Unit-Tests mit 172 Assertions unter PHP 8.5.10.
- 29 Integrationstests mit 507 Assertions unter PHP 8.3.30, WP-CLI 2.12.0, WordPress 7.1.2 und MAMP MySQL 5.7.44; keine Fehler, Fehlschläge oder übersprungenen Tests.
- Unit-Coverage mit PCOV: 409 von 1514 Zeilen (27,01 %), für die neue Laufprotokollierung 105 von 137 Zeilen (76,64 %). Die WP-CLI-Kindprozesse der Integrationstests sind weiterhin nicht im Coverage-Wert enthalten.
- Syntaxprüfung aller elf geänderten oder neuen PHP-Dateien sowie `git diff --check` ohne Fehler.
- Bereinigung der eigenen Testdatenbanken und Arbeitsverzeichnisse; auch das im Befehlsprotokoll aufgezeichnete Sandbox-Verzeichnis existiert nach dem Lauf nicht mehr.

Die Sandbox konfiguriert für jede isolierte Installation ein eigenes privates `RRZE_MIGRATION_RUN_DIR` außerhalb ihrer Webverzeichnisse. Dauerhafte Checkpoints und Paketkopien werden innerhalb der Testumgebung aufbewahrt und erst mit deren abschließender Bereinigung entfernt. Die bestehende lokale WordPress-Installation erhält weder diese Konfiguration noch Laufdaten.

Zusätzlich zum bisherigen Export-/Importvergleich prüfen die Integrationstests:

- Erfolgsprotokoll erst nach Ergebnisprüfung und Bereinigung, private Dateirechte und unveränderte Paketkopie.
- Gezielt ausgelöste Fehler nach Tabellenimport, URL-Ersetzung, Site-Konfiguration, Benutzerübernahme, Referenzkorrektur, Medienübertragung, Rewrite-Regeln und Ergebnisprüfung. Der jeweilige Schritt bleibt als begonnen erkennbar, spätere Schritte werden nicht als abgeschlossen ausgegeben und fremde Inhalte bleiben geschützt.
- Wiederherstellung aus der aufbewahrten Paketkopie nach kontrollierter manueller Löschung ausschließlich einer Testwebsite. Das zuvor neu angelegte globale Konto behält ID und Kontodatensatz; die neue Website erhält eine neue Site-ID und korrekt zugeordnete Inhalte und Medien.
- Zwei gleichzeitig gestartete Testimporte auf unterschiedliche Zieladressen: Der zweite scheitert an der Installationssperre vor Site-Anlage; der erste kann anschließend erfolgreich enden.
- `SIGTERM` an einer Schrittgrenze, nativer PHP-Exit sowie `SIGKILL` bei angehaltenem Testprozess ohne aktive Kindbefehle. Statusabfragen erkennen Unterbrechungen und verändern weder Checkpoints noch Zieltabellen. Verbliebene Arbeitsdateien werden im Test erst nach bestätigtem Prozessende anhand ihres aufgezeichneten Pfads und innerhalb der Testumgebung entfernt.
- Verlust der Datenbanksperre sowie absichtlich beschädigte Medien und veränderte globale Benutzerprofile: Die nächste Prüfung muss abbrechen und darf keinen Erfolg melden. Die Profiländerung dieser synthetischen Testinjektion wird anschließend gezielt zurückgesetzt.

Die Unit-Tests prüfen atomare Checkpoint-Veröffentlichung, private Speicherorte, Pfadabgrenzung, Paketkopien, Zustandsübergänge und das Verbot einer Erfolgsmeldung ohne abgeschlossene Prüfung und Bereinigung. Sie verwenden keine WordPress-Konfiguration und keine echten Zugangsdaten.

Ein Native-Exit oder harter Abbruch während eines tatsächlich noch laufenden externen Datenbankprozesses wird hier nicht als sicher bereinigt behauptet. Die [Wiederherstellungsanleitung](migration-recovery.md) verlangt deshalb vor administrativen Löschungen die Kontrolle sämtlicher beteiligter Prozesse. Die Tests ersetzen weder die unabhängige Sicherung einer vorab gelöschten Website noch eine Produktionsabnahme mit aktiven Erweiterungen und SSO.

## Nächste Erweiterungen

Vollständige SQL-Isolation, konkurrierende Änderungen außerhalb der Migrationssperre, Erweiterungskompatibilität und reale SSO-Anmeldungen bleiben offen. Paket 5 ergänzt nachvollziehbare Abbruchzustände und die Wiederherstellung durch einen frischen Import nach manueller Löschung; der Umgang mit aktiven Kindprozessen und unabhängigen Netzwerksicherungen bleibt eine administrative Aufgabe. Paket 4 ergänzt Format-/Integritätsprüfung, Ressourcengrenzen und Prüfungen auf Restressourcen. Die vorhandenen SQL-Prüfungen sind keine Isolation für beliebige fremde SQL-Dateien. Die hier verifizierten Tests verwenden kontrollierte Exporte und gezielt veränderte synthetische Pakete. Ein tatsächlicher GitHub-CI-Lauf dieses Standes wurde in dieser Arbeit nicht durchgeführt.
