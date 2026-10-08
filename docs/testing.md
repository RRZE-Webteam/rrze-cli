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

## Lokal verifizierter Stand von Paket 6

Am 6. Oktober 2026 erfolgreich ausgeführt:

- 125 Unit-Tests mit 190 Assertions unter PHP 8.5.10.
- 39 Integrationstests mit 649 Assertions unter PHP 8.3.30, WP-CLI 2.12.0, WordPress 7.1.2 und MAMP MySQL 5.7.44; keine Fehler, Fehlschläge oder übersprungenen Tests.
- Unit-Coverage mit PCOV: 445 von 1679 Zeilen (26,50 %). Der Prozentwert enthält weiterhin keine WP-CLI-Kindprozesse und damit auch nicht die interaktiven Integrationstests.
- Syntaxprüfung aller 13 geänderten oder neuen PHP-Dateien, `git diff --check` und Prüfung der lokalen Dokumentationslinks ohne Fehler.
- Bereinigung der eigenen Testdatenbanken und Arbeitsverzeichnisse; das im Befehlsprotokoll aufgezeichnete Sandbox-Verzeichnis existiert nach dem Lauf nicht mehr.

Die Unit-Tests prüfen sichere Vorgaben und Eingabegrenzen: Leere Bestätigungen stimmen nicht zu, falsche Ziel-URLs werden nicht übernommen, Eingabeende und `!quit` brechen ab. Terminal-Steuerzeichen werden in den Planausgaben sichtbar maskiert und in Antworten zurückgewiesen. Ein geänderter Benutzerplan oder eine andere Site-ID kann eine frühere Freigabe nicht übernehmen; schwankender freier Speicher wird weiterhin über die Kapazitätsprüfung bewertet.

Die Integrationstests starten WP-CLI in echten Unix-Pseudoterminals und beantworten Fragen erst, nachdem der jeweilige Prompt erscheint. Sie prüfen:

- Export der ausgewählten Quellwebsite mit standardmäßig enthaltenen Medien, Dateinamen mit Leerzeichen und Schutz vorhandener Ausgabedateien.
- Vorausgewählte Importvorschau, wiederholte Eingabe bei ungültigen Dateien oder URLs und belegten Zieladressen; unveränderte Zieldatenbank, Uploads und Laufverzeichnisse.
- Ablehnung von Pipes, `--yes`, `--quiet`, Single-Site-Zielen und beschädigten Paketen.
- Abbruch bei falscher Bestätigungs-URL, Enter auf der abschließenden Frage, `!quit`, Eingabeende, `SIGINT` und `SIGTERM` während der Bestätigung. Der Import legt keine Site an; die Arbeitsdateien werden bereinigt und die private Paketkopie bleibt im fehlgeschlagenen Lauf erhalten.
- Erfolgreicher Import trotz anschließender Veränderung der ursprünglichen ZIP-Datei: Die geprüfte private Kopie bestimmt die Ausführung.
- Zwischen Prüfung und Zustimmung extern angelegte Benutzer oder Websites: Die Freigabe deckt keinen veränderten Plan und keine belegte Zieladresse ab. Die nach der gezielten externen Änderung aufgenommenen Zieldaten bleiben durch den Import unverändert.
- Zusätzliche Zustimmung bei fehlenden Paketmedien; Enter führt zum Abbruch.

Ein Signaltest zeigte, dass blockierendes `fgets()` die Verarbeitung von PHP-Signalhandlern während einer Terminaleingabe verzögern kann. Der Wizard liest Terminaleingaben deshalb mit begrenzten nichtblockierenden Abfragen und stellt den vorherigen Blockiermodus anschließend wieder her. Die Signale werden direkt an den eigenen Testprozess gesendet; es wird kein fremder Prozess beendet.

Die Pseudoterminal-Tests laufen in denselben isolierten WordPress-Kopien wie die übrigen Integrationstests. Sie benötigen Unix-PTY-Unterstützung. Die Terminalpfade der WP-CLI-Kindprozesse sind wie bisher nicht in der Unit-Coverage enthalten.

## Gezielte Upload-Ausschlüsse

Am 6. Oktober 2026 mit dieser Ergänzung erfolgreich ausgeführt: 140 Unit-Tests mit 218 Assertions unter PHP 8.5.10 und 40 Integrationstests mit 679 Assertions unter PHP 8.3.30, WP-CLI 2.12.0, WordPress 7.1.2 und MAMP MySQL 5.7.44. Der Integrationsbericht enthält keine Fehler, Fehlschläge oder übersprungenen Tests; die eigenen Testdatenbanken und das protokollierte Sandbox-Verzeichnis wurden bereinigt. Syntaxprüfung aller neun geänderten oder neuen PHP-Dateien, lokale Dokumentationslinks und `git diff --check` sind fehlerfrei.

Die Unit-Zeilenabdeckung beträgt 512 von 1732 Zeilen (29,56 %), für die Paketprüfung 159 von 183 Zeilen (86,89 %) und für die Ausschlussregeln 22 von 23 Zeilen (95,65 %). Die WP-CLI-Kindprozesse bleiben außerhalb dieses Coverage-Werts.

Eine zusätzliche Regression bildet ein Plugin-Arbeitsverzeichnis `wp-migrate-db` mit `.htaccess`, `index.php` und einer synthetischen Sicherungsdatei ab. Ohne ausdrücklichen Ausschluss muss der Export mit dem konkreten Dateipfad abbrechen und die unvollständige Ausgabedatei entfernen. Mit `--uploads --exclude-upload-dirs=wp-migrate-db` oder der entsprechenden Wizard-Eingabe wird ausschließlich dieses Verzeichnis ausgelassen; `wp-migrate-db-keep` und normale Medien bleiben enthalten. Quelle und Kontrollwebsite werden vor und nach dem vollständigen Export/Import verglichen.

Der Test prüft den Eintrag im Paketmanifest und JSON-Plan sowie die zusätzliche Zustimmung im Import-Wizard. Eine leere Zustimmung verändert keine Zieldaten. Der folgende bestätigte Import muss normale Medien korrekt übertragen und darf das ausgeschlossene Verzeichnis nicht anlegen.

Unit-Tests prüfen zusätzlich Verzeichnisgrenzen, Pfadtraversal, absolute Pfade, unbekannte und symbolisch verlinkte Verzeichnisse sowie widersprüchliche Manifeste, die angeblich ausgeschlossene Dateien dennoch enthalten. Fehlerausgaben nennen den betroffenen Paketpfad ohne rohe Terminal-Steuerzeichen. Die vorhandenen Regeln gegen ausführbare Dateien, Serverkonfiguration und unsichere Archivpfade bleiben aktiv.

## Lange SQL-Werte

Am 6. Oktober 2026 erfolgreich ausgeführt: 157 Unit-Tests mit 251 Assertions unter PHP 8.5.10 und 42 Integrationstests mit 720 Assertions unter PHP 8.3.30, WP-CLI 2.12.0, WordPress 7.1.2 und MAMP MySQL 5.7.44. Der Integrationsbericht enthält keine Fehler, Fehlschläge oder übersprungenen Tests; Testdatenbanken und Sandbox-Verzeichnis wurden bereinigt. Die Unit-Zeilenabdeckung beträgt 562 von 1781 Zeilen (31,56 %), für `Migration\Sql` 62 von 62 Zeilen (100 %). Die WP-CLI-Kindprozesse bleiben außerhalb der Unit-Coverage. Syntaxprüfung aller elf geänderten oder neuen PHP-Dateien, lokale Dokumentationslinks und `git diff --check` sind fehlerfrei.

Die Meldung `Could not inspect SQL structure within parser limits.` ließ sich bei einem kontrollierten Export mit rund 3,6 MB SQL reproduzieren. Bereits einzelne lange Textwerte überforderten die bisherige Regex: PHP meldete je nach Laufzeit `Recursion limit exhausted` oder `JIT stack limit exhausted`. Die SQL-Datei lag dabei deutlich unter der Paketgrenze von 64 MiB.

Die Strukturprüfung überspringt Zeichenketten und gewöhnliche Kommentare jetzt mit einem Scanner ohne Regex-Rekursion. Maskierte Bereiche behalten ihre Byte-Länge, sodass beim Umschreiben der Tabellennamen alle Nutzdaten einschließlich serialisierter Werte unverändert bleiben. Ausführbare MySQL-Versionskommentare bleiben für die Tabellenprüfung sichtbar; nicht abgeschlossene Zeichenketten oder Kommentare führen mit einer Byte-Position zum Abbruch, ohne deren Inhalt auszugeben. Regex-Fehler bei der anschließenden Tabellensuche brechen ebenfalls ausdrücklich ab. Paketgrenzen und Prüfung der erlaubten Tabellen bleiben aktiv.

Die Unit-Regressionen verwenden Werte mit mehr als 1 MB, niedrige PCRE-Grenzen mit ein- und ausgeschaltetem JIT, Unicode, maskierte und verdoppelte Anführungszeichen, Backtick-Bezeichner und Kommentare. Sie prüfen außerdem, dass SQL-ähnlicher Text innerhalb eines Werts keine Tabellenzuordnung auslöst und fremde Tabellen hinter langen Werten oder in ausführbaren Kommentaren weiterhin abgewiesen werden. Der Scanner berücksichtigt die [MySQL-Regel für Kommentare mit zwei Bindestrichen](https://dev.mysql.com/doc/refman/8.0/en/ansi-diff-comments.html), damit eine Subtraktion wie `1--1` keine nachfolgenden Tabellenreferenzen verbirgt.

Ein Integrationstest ergänzt ein gültiges synthetisches Paket um eine serialisierte WordPress-Option mit mehr als 1 MB. Er prüft den unverändernden Dry-run, einen vollständigen Import und den SHA-256-Wert der anschließend aus WordPress gelesenen Option. Quelle und Kontrollwebsite müssen unverändert bleiben. Ein weiterer Test prüft, dass nicht abgeschlossene SQL-Zeichenketten und Kommentare vor jeder Site-Anlage abgewiesen werden und Zieldatenbank sowie Uploads unverändert bleiben.

Zusätzlich wurde die korrigierte SQL-Prüfung am ursprünglichen Archiv ausschließlich lesend ausgeführt: Alle 23 Tabellen stimmen mit dem Manifest überein. Eine Umbenennung auf synthetische Zieltabellen und die anschließende Rückumbenennung erhalten den SQL-Inhalt bytegenau. Das reale Paket wurde dabei nicht importiert; dieser Befund bestätigt die SQL-Prüfung, keinen vollständigen Import dieser Website.

## Import ohne automatische Upload-Übertragung

Am 7. Oktober 2026 erfolgreich ausgeführt: 162 Unit-Tests mit 257 Assertions unter PHP 8.5.10 sowie der vollständige Integrationslauf mit 48 Tests und 894 Assertions unter PHP 8.3.30, WP-CLI 2.12.0, WordPress 7.1.2 und MAMP MySQL 5.7.44. Der Integrationsbericht enthält keine Fehler, Fehlschläge oder übersprungenen Tests; eigene Testdatenbanken und Sandbox-Verzeichnis wurden bereinigt. Die Unit-Zeilenabdeckung beträgt 573 von 1835 Zeilen (31,23 %); die WP-CLI-Kindprozesse einschließlich der neuen Wizard-Abläufe bleiben außerhalb dieser Abdeckung. Syntaxprüfung der zwölf geänderten oder neuen PHP-Dateien, lokale Dokumentationslinks und `git diff --check` sind fehlerfrei.

Eine anschließend ergänzte strikte Prüfung des booleschen Flags wurde zusätzlich mit dem gezielten Layout-Integrationstest auf einem frischen Testsystem verifiziert: 1 Test mit 48 Assertions, ohne Fehler, Fehlschläge oder übersprungene Tests; die eigene Testumgebung wurde bereinigt. Er prüft insbesondere, dass `--no-skip-uploads` die Layout-Einschränkung weiterhin ablehnt und `--skip-uploads` sie ausdrücklich für den manuellen Weg freigibt.

Bei nicht unterstützten Upload-Konfigurationen bietet der Wizard sowohl für `preview` als auch für `import` eine ausdrückliche Fortsetzung ohne Uploads mit Vorgabe `no` an. Nur die typisierte Layout-Einschränkung löst diese Auswahl aus. Direkte Aufrufe benötigen `--skip-uploads`. Eine deaktivierte Option darf nicht als Zustimmung behandelt werden; Zeichenketten als Flag-Werte werden abgewiesen.

Die Integrationstests prüfen Ablehnung durch Enter, `!quit` und Eingabeende, eine unverändernde bestätigte Vorschau sowie einen vollständigen Wizard-Import mit einem Upload-Filter. Vorhandene Dateien am individuellen Medienort und alle Standard-Uploads müssen unverändert bleiben. Ein weiterer Import nutzt `--skip-uploads` direkt bei Standardpfaden. In beiden Fällen sind keine Dateien übertragen, der Medien-Schritt ist `skipped`, der Datenimport ist abgeschlossen und `uploads.verified` bleibt `false`. Text- und JSON-Status erhalten den Hinweis auf die ausstehenden Medienarbeiten. Die normale Website-URL-Ersetzung läuft weiterhin; die gesonderte Upload-Site-ID-Ersetzung entfällt.

Weitere Fälle prüfen benutzerdefinierte `upload_path`-Optionen, `UPLOADS`, `BLOGUPLOADDIR` und alte Multisite-Layouts im Dry-run, einschließlich der Ablehnung ohne aktive Option. Bereits vorhandene Websites, verwaiste Tabellen, Uploads und Mitgliedschaften sowie beschädigte Medien-Prüfsummen blockieren weiterhin. Ein beschädigtes Paket darf im Wizard keine Fortsetzung anbieten. Nach der Freigabe hinzukommende Upload-Einschränkungen oder Restdateien brechen bei der erneuten Vorprüfung ab, ohne eine Website anzulegen.

Unit-Tests prüfen, dass eine veränderte Medienentscheidung keine frühere Planfreigabe übernehmen kann, dass Überspringen einen ausdrücklichen Plan voraussetzt und dass andere Schritte keinen Zustand `skipped` vortäuschen können. Ein Mediennachweis darf erst nach erfolgreicher Ergebnisprüfung protokolliert werden. Die bestehenden Tests für automatische Medienübertragung prüfen zusätzlich `uploads.verified: true`.

Die Tests verwenden ausschließlich eigene lokale Datenbanken und temporäre WordPress-Kopien. Ein tatsächlicher `rsync`, reale fremde Upload-Filter und die betriebliche Medienprüfung werden dadurch nicht abgenommen. Benutzerdefinierte Medienorte werden im manuellen Modus vom Importer nicht ermittelt oder auf Restbestände geprüft; die vorhandenen WordPress-/Plugin-Hooks bleiben aktiv.

## Gemeinsame private Paketablage

Am 7. Oktober 2026 erfolgreich ausgeführt: 178 Unit-Tests mit 284 Assertions unter PHP 8.5.10 und 50 Integrationstests mit 966 Assertions unter PHP 8.3.30, WP-CLI 2.12.0, WordPress 7.1.2 und MAMP MySQL 5.7.44. Der vollständige Integrationslauf enthält keine Fehler, Fehlschläge oder übersprungenen Tests; die eigenen Testdatenbanken und das protokollierte Sandbox-Verzeichnis wurden bereinigt. Die Unit-Zeilenabdeckung beträgt 601 von 1875 Zeilen (32,05 %), für `PackageStorage` 25 von 28 Zeilen (89,29 %). WP-CLI-Kindprozesse bleiben außerhalb dieser Abdeckung. Syntaxprüfung der insgesamt 17 geänderten oder neuen PHP-Dateien, lokale Dokumentationslinks und `git diff --check` sind fehlerfrei.

ZIP-Export und Import teilen sich `RRZE_MIGRATION_RUN_DIR` beziehungsweise `--run-dir`. Die Unit-Tests prüfen getrennte Exportordner bei gleichen Dateinamen, private Verzeichnis- und Dateimodi, reine Pfadplanung ohne Anlage von Verzeichnissen sowie die Ablehnung von Pfadtraversal, Webroot-Eingaben und Umwegen über Verzeichnislinks. Absolute private Eingaben funktionieren auch ohne konfigurierten Stammordner. Fehlende relative Eingaben dürfen nicht im WordPress-Verzeichnis gesucht werden.

Die Integrationstests exportieren in einen neuen privaten Stammordner und verwenden das dortige Paket für Vorschau und vollständigen Import. Die Vorschau muss Datenbank und Ablagestruktur unverändert lassen; der Import legt eine eigenständige Paketkopie mit Journal an und erhält den Originalexport bytegenau. Weitere Prüfungen lehnen öffentliche Eingaben auch beim Dry-run und bei einem Verzeichnislink in den Webroot ab, ohne die Originaldateien zu verschieben oder zu löschen. Absolute Export-Ausgabepfade und Stammordner innerhalb von `wp-content` werden zurückgewiesen.

Die bisherigen Wizard-Tests verwenden die neue Verzeichnisauswahl. Sie prüfen wiederholte Exporte mit gleichem Dateinamen, `0700` für Exportordner, `0600` für ZIP-Dateien sowie das Ausbleiben eines Exportordners bei Abbruch. Ein Exportfehler durch nicht erlaubte Upload-Dateien muss die begonnene private Ausgabe entfernen. Die Testpakete selbst werden im Testaufbau nur noch zwischen privaten Ablagen übertragen; öffentliche ZIP-Dateien entstehen ausschließlich gezielt für Ablehnungstests mit synthetischen Daten.

Die Status- und Wiederherstellungstests verwenden unverändert die bisherigen Importlauf-Pfade. Separate Rohbefehle `export tables` und `export users` behalten ihre explizite Pfadauswahl; deren bereits vorhandene Überschreibschutz-Tests bleiben aktiv. Reale Pakete oder Konfigurationsdateien der lokalen Installation werden bei diesen Tests nicht verschoben oder verändert.

## ZIP-Auswahl im Import-Wizard

Am 8. Oktober 2026 erfolgreich ausgeführt: der vollständige Integrationslauf mit 53 Tests und 1026 Assertions. Nach der zusätzlichen Absicherung gegen veraltete Pfadauflösungen wurden die sieben betroffenen Integrationsfälle erneut erfolgreich ausgeführt (133 Assertions), ebenso alle 184 Unit-Tests (305 Assertions). Beide Integrationsläufe enthalten keine Fehler, Fehlschläge oder übersprungenen Tests und haben ihre eigenen Testdatenbanken und Sandbox-Verzeichnisse bereinigt. Die eingesetzten PHP-, WP-CLI-, WordPress- und MySQL-Versionen entsprechen dem vorherigen Abschnitt. Die Unit-Zeilenabdeckung beträgt 638 von 1936 Zeilen (32,95 %), für `PackageStorage` 62 von 69 Zeilen (89,86 %); WP-CLI-Kindprozesse bleiben außerhalb dieser Messung. Syntaxprüfung der vier geänderten PHP-Dateien und `git diff --check` sind fehlerfrei.

Die Unit-Tests prüfen die unverändernde ZIP-Suche in privaten Verzeichnissen, die Sortierung nach Dateiänderungsdatum und Pfad sowie gleichnamige Pakete in unterschiedlichen Unterordnern. Symbolische Links, Schleifen und Dateinamen mit Steuerzeichen bleiben außerhalb der Liste. Leere und fehlende Stammverzeichnisse werden nicht verändert; Anzeige-, Such- und Tiefengrenzen liefern einen Hinweis auf eine unvollständige Liste. Nicht angezeigte private Pfade bleiben direkt verwendbar. Ein separater Prozess tauscht nach der Suche ein Unterverzeichnis gegen einen Link aus: Die erneute Eingabeprüfung muss den aktuellen Pfad statt eines zwischengespeicherten Ergebnisses prüfen.

Die PTY-Integrationstests wählen ein Paket per Nummer, prüfen ungültige und leere Eingaben sowie die manuelle absolute Eingabe bei leerer Liste. Eine Vorschau erhält Datenbank, Uploads und Ablagestruktur. Während der Auswahl entfernte oder durch Links ersetzte Dateien werden abgewiesen; beschädigte ZIP-Inhalte bestehen auch nach einer Nummernauswahl die Paketprüfung nicht. `!quit` und Eingabeende hinterlassen keinen Importlauf. Ein vollständiger Import wählt ebenfalls per Nummer und verwendet nach der Freigabe weiterhin die geprüfte private Kopie, selbst wenn sich die ursprüngliche Datei inzwischen geändert hat.

## Exportquelle über Website-ID

Am 8. Oktober 2026 erfolgreich ausgeführt: alle 201 Unit-Tests mit 322 Assertions sowie elf gezielte Integrationstests in zwei getrennten Läufen (6 Tests/157 Assertions und 5 Tests/90 Assertions). Geprüft wurden die neue ID-Auswahl, die bisherigen Exportbestätigungen, Upload-Ausschlüsse, die unverändernde Importvorschau, der Terminalschutz und vollständige Export-/Import-Abläufe. Beide Integrationsläufe enthalten keine Fehler, Fehlschläge oder übersprungenen Tests; die eigenen Testdatenbanken und Sandbox-Verzeichnisse wurden bereinigt. Die vollständige Integrationssuite wurde für diese Erweiterung nicht erneut ausgeführt. Die Unit-Zeilenabdeckung beträgt 642 von 1982 Zeilen (32,39 %); Website-Auflösung und Neustart in WP-CLI-Kindprozessen bleiben außerhalb dieser Messung. Verwendet wurden dieselben PHP-, WordPress-, WP-CLI- und MySQL-Versionen wie im vorherigen Abschnitt. Syntaxprüfung der fünf geänderten oder neuen PHP-Dateien und `git diff --check` sind fehlerfrei.

Die Unit-Tests prüfen ganzzahlige IDs einschließlich der Integer-Grenze und lehnen ungültige, negative, abgeschnittene oder überlaufende Werte ab. Die ID-Auflösung und der Kontextwechsel werden in echten WP-CLI-Prozessen geprüft: Direkter ZIP-Export und Wizard exportieren anhand der ID die erwarteten Tabellen, Mitglieder, Metadaten und Uploads. Ein nur beim Start der ausgewählten Website registrierter Plugin-Filter muss im Export wirksam sein. Damit reicht ein nachträgliches `switch_to_blog()` im falschen Plugin-Kontext nicht aus, um den Test zu bestehen. Ein weiterer Export wählt die Hauptsite per ID trotz einer anfänglich auf eine Subsite zeigenden `--url`.

Interaktive Tests prüfen die ID-Eingabe mit Korrektur ungültiger Angaben, die Zuordnung von ID und URL und die ausdrückliche Zustimmung mit Vorgabe `no`. Enter, `no`, `!quit` und Eingabeende brechen vor der Verzeichnisauswahl und ohne Exportdateien ab. Unbekannte IDs, die ID-Option bei Einzelinstallationen und beim Import sowie unerlaubte Tabellen werden abgelehnt. Ein Plugin simuliert einen falschen Website-Kontext nach dem Neustart: Der Befehl muss abbrechen und darf weder die andere Website exportieren noch erneut endlos starten. Datenbank-Snapshots der Quelle bleiben bei diesen Tests unverändert.

## Nächste Erweiterungen

Vollständige SQL-Isolation, konkurrierende Änderungen außerhalb der Migrationssperre, Erweiterungskompatibilität und reale SSO-Anmeldungen bleiben offen. Paket 5 ergänzt nachvollziehbare Abbruchzustände und die Wiederherstellung durch einen frischen Import nach manueller Löschung; der Umgang mit aktiven Kindprozessen und unabhängigen Netzwerksicherungen bleibt eine administrative Aufgabe. Paket 4 ergänzt Format-/Integritätsprüfung, Ressourcengrenzen und Prüfungen auf Restressourcen. Die vorhandenen SQL-Prüfungen sind keine Isolation für beliebige fremde SQL-Dateien. Die hier verifizierten Tests verwenden kontrollierte Exporte und gezielt veränderte synthetische Pakete. Ein tatsächlicher GitHub-CI-Lauf dieses Standes wurde in dieser Arbeit nicht durchgeführt.
