# Export und Import mit dem Migrationsassistenten

Der Assistent führt durch die Auswahl der Eingaben, zeigt den Migrationsplan und verlangt vor der Ausführung eine Bestätigung. Er verwendet dieselben Prüfungen und Abläufe wie die direkten Befehle. Ein Import legt ausschließlich eine neue Website an; eine vorhandene Website wird weder übernommen noch automatisch gelöscht.

## Start und Umgebung

Den Befehl im Hauptverzeichnis der betreffenden WordPress-Installation in einem interaktiven Terminal starten:

```sh
wp rrze-migration wizard
```

Im interaktiven Terminal verwendet der Assistent Laravel Prompts mit Auswahl per Pfeiltasten und Enter, umrahmten Eingaben und farbigen Statusmarkierungen. Ohne Unterauswahl fragt er nach `import` oder `export`. Am Anfang zeigt er das WordPress-Verzeichnis, die aktuelle Website mit ID und bei Multisite das aktuelle Netzwerk. Die globalen WP-CLI-Parameter, insbesondere `--url`, wählen diesen anfänglichen Kontext. Für den Export kann anschließend eine Website-ID derselben Multisite-Installation ausgewählt werden. Der Assistent wechselt nicht zwischen Servern und überträgt kein Paket auf einen anderen Rechner.

Die Fragen sind wie die übrigen Migrationsausgaben auf Englisch. Bei Texteingaben stehen Vorgaben in eckigen Klammern und gelten für Enter. Bei Auswahllisten gilt der markierte Eintrag. `!quit` bricht an jeder Eingabe ab. ZIP-Importpfade beziehen sich auf das private Migrationsverzeichnis oder werden absolut außerhalb der Webverzeichnisse angegeben. Exportnamen enthalten ausschließlich den Dateinamen ohne Verzeichnis. Leerzeichen in Dateinamen werden ohne Shell-Auswertung verarbeitet; bei der interaktiven Eingabe keine zusätzlichen Anführungszeichen setzen.

Mit `--plain` bleiben die bisherigen zeilenweisen Eingaben verfügbar. `--no-color` oder `TERM=dumb` schalten ebenfalls auf Textbedienung um; `--no-color` deaktiviert zusätzlich die WP-CLI-Farben. Kann das Terminal nicht gesteuert werden, bricht der Assistent mit einem Hinweis auf `--plain` ab. Es werden dabei keine Vorgaben automatisch bestätigt.

```sh
wp rrze-migration wizard import --plain
wp rrze-migration wizard import --verbose
```

Die visuelle Planansicht fasst Tabellen und Benutzeraktionen zusammen. `--verbose` zeigt die einzelnen Tabellen und Benutzeraktionen. Vollständige Quell- und Zieladressen, Paketpfad, Medienentscheidung und Ausschlüsse bleiben sichtbar. Lange Einträge können innerhalb einer Auswahlliste gekürzt erscheinen; die aufgelöste Auswahl wird anschließend vollständig ausgegeben.

Beide Abläufe fragen nach `Private migration directory outside web roots`; beim Export erfolgt vorher die Auswahl und Bestätigung der Quelle. Eine konfigurierte `RRZE_MIGRATION_RUN_DIR` dient als Vorgabe. Der Pfad muss absolut und privat sein und außerhalb von WordPress und `wp-content` liegen; andere Webserver dürfen ihn ebenfalls nicht veröffentlichen. Das Elternverzeichnis muss bereits existieren. Die Pfadprüfung und eine Vorschau legen dort noch keine Verzeichnisse an. Bei einem bestätigten Export oder einem vorbereiteten echten Import wird ein fehlendes Stammverzeichnis mit Modus `0700` angelegt.

## Exportieren

```sh
wp rrze-migration wizard export --site-id=5
```

Ohne `--site-id` zeigt die visuelle Oberfläche eine Auswahlliste mit ID und URL, wenn das aktuelle Netzwerk höchstens 20 Websites enthält. Die Vorgabe ist eine manuelle ID-Eingabe. Bei mehr als 20 Websites entfällt die Liste vollständig; der Assistent fragt direkt nach `Source website ID`, wie auch im Textmodus. Zur Entscheidung werden höchstens 21 Websites abgefragt; große Netzwerke werden nicht vollständig geladen. Bei der manuellen Eingabe ist die aktuelle Website-ID die Vorgabe. Der bisherige URL-Aufruf bleibt möglich und bestimmt diese Vorgabe:

```sh
wp rrze-migration wizard export --url=https://source.example.test/site/
```

Die ID muss eine positive ganze Zahl sein und zu einer vorhandenen Website gehören. Bei einer anderen Website wird WordPress in einem neuen WP-CLI-Prozess mit deren registrierter Adresse geladen, damit auch ihre Plugins, Rollen und Upload-Einstellungen aktiv sind. Erst nach Prüfung der geladenen ID folgt die Bestätigung. Eine falsche Zuordnung führt zum Abbruch. Wird zusätzlich `--url` angegeben, entscheidet die ausdrücklich ausgewählte ID über die Exportquelle. Bei einer Einzelinstallation wird die aktuelle Website bestätigt; `--site-id` ist dort nicht verfügbar.

1. Die angezeigte Zuordnung `Export source: ID 5 | URL: …` prüfen und `Export this website` ausdrücklich bestätigen: im visuellen Modus `Yes` auswählen bzw. `y` drücken und Enter; im Textmodus `yes` eingeben. Vorgabe bleibt `No`. Enter, `no`, `!quit` oder Eingabeende brechen vor der Anlage von Exportdateien ab. Bei einer falschen Quelle abbrechen und mit der richtigen ID neu starten.
2. Das private Migrationsverzeichnis bestätigen und einen ZIP-Dateinamen ohne Pfad wählen. Eine fehlende Endung `.zip` wird ergänzt. Jeder Export erhält einen eigenen Ordner `export-<UTC-Zeitstempel>-site-<ID>-<Zufallskennung>`; derselbe Dateiname überschreibt deshalb keinen älteren Export.
3. Bei Bedarf zusätzliche, ausschließlich dieser Website gehörende Tabellen angeben. Die automatische Auswahl entspricht `export all`; bei Hauptsites sind zusätzliche eigene Tabellen ausdrücklich zu benennen und fachlich zu prüfen.
4. Den externen rsync-Transfer einplanen. Der ZIP enthält immer ein Medienmanifest und keine Mediendateien. Schreibzugriffe auf der Quelle vor dem Export stoppen und einen passenden Mediensnapshot aufbewahren. Plugins und Themes werden separat im Ziel bereitgestellt.
5. Gegebenenfalls Verzeichnisse vom Medienmanifest und Transfer ausdrücklich ausschließen. Die Eingabe bleibt normalerweise leer. Für ein nicht zu migrierendes Plugin-Arbeitsverzeichnis wie `wp-migrate-db` genau diesen relativen Namen eingeben; mehrere Namen werden mit Komma getrennt. Beim Export der modernen Netzwerk-Hauptseite wird `sites` automatisch ausgeschlossen, da dort die Medien anderer Websites liegen.
6. Den Plan mit Quelle, Tabellenumfang, Ausgabeort, Medienquellverzeichnis und Ausschlüssen lesen. Die angezeigte vollständige Quell-URL wiederholen und anschließend ausdrücklich `Yes` bestätigen (Textmodus: `yes`).

Erst nach dieser Bestätigung werden der angezeigte Export-Unterordner und die ZIP-Datei angelegt. Der Ordner erhält Modus `0700`, die Datei `0600`. Abbrüche vor der Bestätigung hinterlassen keinen Exportordner; bei einem behandelten Exportfehler wird die unvollständige Ausgabe entfernt. Der Export enthält keine Benutzerpasswörter oder Sitzungstoken. Die ZIP-Datei anschließend über den betrieblich vorgesehenen Weg in ein privates Verzeichnis des Zielsystems übertragen, beispielsweise `<RRZE_MIGRATION_RUN_DIR>/incoming/website.zip`. Bei einem Import auf demselben System kann der angezeigte Exportpfad direkt verwendet werden.

Enthalten Uploads `.htaccess`, `index.php` oder andere nicht erlaubte Dateien, benennt die Fehlermeldung den betroffenen Paketpfad. Solche Schutz- oder Konfigurationsdateien auf der Quelle nicht löschen, um den Export zu ermöglichen. Ein ausdrücklich ausgewähltes Verzeichnis wird einschließlich seiner Unterverzeichnisse nur aus dem Paket ausgelassen; seine Quelldateien bleiben erhalten. Absolute Pfade, Pfadtraversal, symbolische Links und fehlende Verzeichnisse sind als Ausschluss unzulässig. Die Auswahl arbeitet mit vollständigen Verzeichnisnamen, nicht mit Wildcards.

Die Ausschlüsse stehen im Paketmanifest unter `excluded_upload_directories` und im späteren Importplan. Beim direkten Export lautet die entsprechende Option `--exclude-upload-dirs=wp-migrate-db` ohne weiteren Medienschalter. Ein ähnlich benannter Ordner wie `wp-migrate-db-other` bleibt enthalten. Ein solches Paket ist keine vollständige Sicherung des ursprünglichen Upload-Verzeichnisses.

## Import zunächst prüfen

```sh
wp rrze-migration wizard import
```

Der Import benötigt eine Multisite. Der Assistent fragt zuerst nach dem privaten Migrationsverzeichnis und anschließend nach einer lesbaren Paketdatei, einer ausdrücklich angegebenen neuen Ziel-URL und gegebenenfalls Post-Metafeldern mit numerischen Benutzer-IDs. Eine bereits belegte Adresse wird zurückgewiesen; es gibt keinen Löschschritt. War diese Adresse bisher belegt, muss die alte Website vorher manuell in Network Admin gelöscht worden sein. Vor dieser Löschung bleibt eine unabhängige Sicherung erforderlich.

Gefundene ZIP-Dateien erscheinen im visuellen Modus in einer Pfeiltasten-Auswahl. Die Zeilen beginnen mit dem ZIP-Dateinamen und nennen das Änderungsdatum in UTC. Bei den vom Export angelegten Ordnern folgt `Export · site <ID>`. Aufbewahrte `package.zip`-Dateien in Laufordnern erscheinen als `Import copy <kurze Laufkennung>` mit Datum. Andere Unterverzeichnisse werden als zusätzlicher Hinweis angezeigt. Diese Bezeichnungen werden nur aus den Ablagenamen abgeleitet; sie bestätigen weder Paketinhalt noch Importerfolg. Lange Namen werden in der Liste gekürzt. Nach der Auswahl erscheinen der vollständige Pfad, das Änderungsdatum und die Größe in MiB.

Vorgabe ist `Enter a private ZIP path`; Enter allein wählt also keine Datei. Im Textmodus erscheinen die Dateien weiterhin in einer nummerierten Liste mit relativem Pfad, Änderungsdatum in UTC und Größe in MiB. Neuere Änderungen stehen zuerst; bei gleichem Datum entscheidet der Pfad. Die Nummern halten auch gleich benannte Pakete eindeutig auswählbar. Beispielsweise wählt im Textmodus die Eingabe `2` den angezeigten Eintrag `[2]`. Der Assistent zeigt danach den vollständigen ausgewählten Pfad. Ungültige Nummern führen zur erneuten Eingabe.

Die Suche berücksichtigt das private Stammverzeichnis und bis zu drei Unterverzeichnisebenen, darunter Exportordner, `incoming` und aufbewahrte Importkopien. Sie folgt keinen symbolischen Links und öffnet keine ZIP-Inhalte. Es werden höchstens 5000 Verzeichniseinträge untersucht und 50 ZIP-Dateien angezeigt. Wird eine Grenze erreicht oder ein Unterverzeichnis nicht lesbar, weist der Assistent auf die unvollständige Liste hin. Ein Listeneintrag bestätigt weder ein gültiges Paket noch den Erfolg eines früheren Imports; die bisherigen Paketprüfungen erfolgen nach der Auswahl.

Alternativ direkt einen Paketpfad wie `incoming/website.zip` oder `export-<Zeitstempel>-site-<ID>-<Kennung>/website.zip` eingeben. Ein absoluter privater Pfad ist ebenfalls möglich, auch bei leerer Liste oder einem noch nicht angelegten Migrationsverzeichnis. ZIP-Dateien innerhalb von WordPress oder `wp-content` werden abgelehnt, auch wenn ein Verzeichnislink dorthin führt. Alte Pakete zuerst selbst aus dem Webroot in private Ablage verschieben. Es gibt keinen stillen Rückgriff auf das WordPress-Verzeichnis, und die ursprüngliche Eingabedatei wird nicht automatisch verschoben oder gelöscht.

Bei `Next action` startet Enter in beiden Darstellungen die vorausgewählte vollständige Vorprüfung `preview`. Sie zeigt die normalisierte Zieladresse, das Zielnetzwerk, die geschätzte Site-ID, Tabellen, Benutzeraktionen, Referenzfelder und Medien. Dabei entstehen keine Zielwebsite, Benutzerkonten, Zieltabellen, Ziel-Uploads oder dauerhaften Laufdaten. Die privaten Prüfdateien werden anschließend entfernt. WordPress und aktive Plugins werden wie bei anderen WP-CLI-Aufrufen geladen.

## Medien extern übertragen und prüfen

Der Import bereitet den rsync-Transfer immer vor. Die bisherige Auswahl zum Mitimportieren oder Überspringen von Uploads entfällt. Alte Pakete der Version 1 müssen neu exportiert werden. Die neue Version 2 enthält `media.json` mit Quellpfad, Basis-URL, relativen Dateinamen, Größen und SHA-256-Prüfsummen.

Die neue Website erhält ihr eigenes Upload-Verzeichnis. Der Import ermittelt die tatsächliche Medien-URL im WordPress-Kontext dieser Website und passt die Quellverweise darauf an. Filter, die den eigenen Basispfad beibehalten, sind zulässig. Gemeinsame oder individuelle Ziel-Basisverzeichnisse sowie Legacy-Zielstrukturen benötigen weiterhin einen Adapter; die externe Übertragung umgeht diesen Schutz nicht.

Vor der letzten Importbestätigung fragt der Wizard nach der Medienquelle: `Local / mounted directory` oder `Remote via SSH`. Im Textmodus heißen die Antworten `local` und `ssh`; die Vorgabe ist `local`. Bei SSH folgen Benutzer/Host bzw. SSH-Alias und der absolute Snapshot-Pfad auf dem Quellhost. Bei lokaler Übertragung wird der absolute Pfad auf dem Zielserver abgefragt. Der exportierte Medienpfad dient als Vorschlag und muss zum aufbewahrten Snapshot passen. Diese Angaben bereiten nur die Befehle vor; der Wizard prüft dabei weder SSH-Erreichbarkeit noch das Vorhandensein des Snapshots. Ein Abbruch in diesen Fragen erfolgt vor dem Anlegen der Website. Die reine Importvorschau fragt keine Transferangaben ab.

Nach dem Datenimport zeigt eine Übersicht Quelle und Ziel anhand ihrer Medien-URLs, die Übertragungsart, Dateianzahl, Größe und ausgeschlossene Verzeichnisse. Darunter stehen drei Schritte: `1. Preview`, `2. Transfer`, `3. Verify`, jeweils mit Erklärung und kopierbarem Befehl. Die Befehle stehen außerhalb der Infobox und enthalten keine Farben oder Rahmenzeichen. Zeilenumbrüche mit `\` erfolgen ausschließlich zwischen vollständigen Shell-Argumenten; Pfade und ihre Anführungszeichen bleiben zusammen. Beim Kopieren alle Fortsetzungszeilen des jeweiligen Befehls übernehmen.

Der Status lautet weiterhin `media_pending`. Mit `wp rrze-migration media plan RUN_ID --source-host=nutzer@quellhost` lassen sich die Befehle erneut anzeigen. `--source-dir=/pfad/zum/snapshot` wählt einen passenden Snapshot; ohne Quellhost bedeutet diese ausdrückliche Angabe einen lokalen oder eingebundenen Pfad auf dem Zielserver. Die Angaben aus dem Wizard werden nicht im Journal gespeichert; beim erneuten Anzeigen mit `media plan` die gewünschte Quelle wieder angeben. `wizard --plain`, `media plan --plain`, `--no-color` und umgeleitete Ausgabe verwenden die gegliederte Textdarstellung ohne Infobox und Farben.

Die Administration führt zuerst die Vorschau, dann den Transfer aus. Die Befehle kopieren nur Dateien aus der privaten Dateiliste, überschreiben keine vorhandenen Dateien und löschen nichts. Remote-Transfers benötigen rsync ab Version 3.0 auf beiden Hosts. Der Import führt rsync nicht selbst aus.

Anschließend `wp rrze-migration media verify RUN_ID` auf dem Ziel ausführen. Der Befehl prüft Dateien und WordPress-Medienreferenzen, ohne Website-Daten erneut zu importieren. Erst danach wird der Lauf `completed`. Bei einem Fehler Transfer oder Konfiguration korrigieren und die Medienprüfung wiederholen; die Website deshalb nicht löschen. Für geschützte Medien müssen rrze-ac und Webserver-Regeln eingerichtet sein. Der zusätzliche Schalter `--access-checked` bestätigt einen zuvor administrativ durchgeführten HTTP-Zugriffstest.

Die vollständige Anleitung einschließlich Snapshot und geschützter Medien steht unter [Medientransfer](migration-media.md).

## Import ausführen

Den Assistenten erneut starten und bei `Next action` ausdrücklich `import` wählen. Das am Anfang ausgewählte private Migrationsverzeichnis dient auch als Stammverzeichnis für den neuen Importlauf. Einrichtung und Aufbewahrung beschreibt die [Wiederherstellungsanleitung](migration-recovery.md).

Vor dem Bestätigen wird das Paket privat kopiert und geprüft. Der Plan bezieht sich auf genau diese Kopie. Die ausführliche Ansicht (`--verbose` oder Textmodus) nennt die bestehenden WordPress-Konten, die eine neue Website-Mitgliedschaft erhalten, und die fehlenden Konten, die mit zufälligem lokalem Passwort angelegt werden. Konten, die nur von Beiträgen oder Kommentaren referenziert werden und keine Quellmitgliedschaft haben, erscheinen als `reference only; no site membership`; sie erhalten auch am Ziel keine Mitgliedschaft. Fehlende Identitäten für diese Kernreferenzen stoppen bereits die Vorprüfung. SSO-Identitäten werden nicht angelegt oder verändert. Die Medienübertragung bleibt bis zur separaten Prüfung offen.

Enthält das Paket ausdrücklich ausgeschlossene Upload-Verzeichnisse, zeigt der Plan deren Namen. Der Wizard verlangt dafür eine zusätzliche Zustimmung mit Vorgabe `no`: Diese Verzeichnisse werden weder übertragen noch auf Vollständigkeit geprüft. Eine ausdrückliche Auswahl beim Export lockert keine Pfad-, Dateityp- oder Prüfsummenregel für die enthaltenen Dateien.

Die vollständig angezeigte Ziel-URL einschließlich Schema und abschließendem Pfad wiederholen. Danach fragt der Assistent, ob er die neue Website anlegen und den Plan ausführen soll. Die Vorgabe ist `No`; Enter allein lehnt ab. Im visuellen Modus `Yes` auswählen und mit Enter bestätigen, im Textmodus `yes` eingeben. Eine abweichende URL bricht ab.

Nach der Bestätigung wird der Plan unter der Migrationssperre erneut geprüft. Ändern sich Zielressourcen, geschätzte Site-ID, Benutzeraktionen oder andere angezeigte Entscheidungen, stoppt der Import vor der Site-Anlage. Der Assistent muss dann mit einem neuen Plan gestartet werden. Der verfügbare Speicher darf sich verändern, muss aber weiterhin die Kapazitätsprüfung bestehen. Eine Änderung der ursprünglich angegebenen ZIP-Datei ersetzt nicht die bereits geprüfte private Kopie.

Während der Ausführung benennt die Konsole die laufenden Schritte. Die visuelle Ansicht markiert den Beginn mit `→`, einen erfolgreich beendeten Schritt mit `✓` und einen fehlgeschlagenen Schritt mit `✕`. Diese Zeilen bleiben im Terminalverlauf stehen; ein Haken bestätigt nur den jeweiligen Schritt. Erst nach der Ergebnisprüfung und Bereinigung erscheint die Erfolgsmeldung. Inhalte, Medien, Rollen und reale SSO-Anmeldung sind anschließend betrieblich abzunehmen.

## Abbrechen und Fehler untersuchen

Leere Bestätigung, falsche Bestätigungs-URL, `!quit` und Eingabeende führen zu einem Fehlerstatus statt zu einer Erfolgsmeldung. Bei vorhandenem PCNTL werden während der Eingaben auch `Ctrl-C` und `SIGTERM` kontrolliert behandelt. Nach Beginn der Ausführung gelten die Abbruchregeln aus Paket 5: Ein laufender Unterprozess muss zunächst zurückkehren; bereits erfolgte Änderungen bleiben bestehen.

Wurde die Importprüfung bereits vorbereitet, bleiben Paketkopie und Journal auch nach einer abgelehnten Bestätigung erhalten. Das Journal meldet in diesem Fall `failed`, keine Site-ID und die abgeschlossene Arbeitsverzeichnis-Bereinigung. Das ist kein teilweise angelegtes Ziel. Nach einem späteren Ausführungsfehler kann hingegen bereits eine neue Website existieren. Laufkennung und Status unterscheiden diese Fälle:

```sh
wp rrze-migration status RUN_ID --run-dir=/srv/private/rrze-migrations
```

Die Hinweise zu harten Prozessabbrüchen, noch laufenden Kindprozessen und manueller Wiederherstellung gelten unverändert. Der Assistent bietet weder Wiederaufnahme noch automatisches Löschen oder Zurückspielen globaler Tabellen an.

## PHP-Diagnosen und frühe Startmeldungen

Bei `rrze-migration` sammelt das Plugin aktive PHP-Deprecations ab seinem Ladezeitpunkt und zeigt am Ende eine kurze Zusammenfassung auf STDERR. Wiederholungen derselben Datei und Zeile werden gezählt; höchstens 100 unterschiedliche Ursprünge werden gespeichert. Normale PHP-Warnungen und Fehler bleiben sichtbar. `--debug` deaktiviert diese Sammlung für die native Fehlersuche.

Bei einem echten Import wird der Bericht `diagnostics-<Kennung>.json` im privaten Laufverzeichnis gespeichert, bei einem erfolgreichen Export neben der ZIP-Datei. Er ist kein Bestandteil des Exportpakets. Bei einem Exportfehler nach Einrichtung der Ablage liegt er im privaten Stammverzeichnis. Verzeichnisse bleiben `0700`, Berichte `0600`. Vorschau und Abbruch vor Einrichtung der Ablage erzeugen keine dauerhafte Diagnosedatei; die Zusammenfassung nennt dann bis zu fünf Ursprünge direkt im Terminal.

Der Bericht enthält Typ, Datei/Zeile beziehungsweise internen Befehlsnamen und Häufigkeit. Fehlermeldungstexte, Befehlsargumente und SQL werden nicht protokolliert. Diagnosen abgefangener WP-CLI-Unterprozesse werden als Befehlsereignis gezählt; ein erfolgreicher Unterprozess mit STDERR-Ausgabe erhält einen sichtbaren Hinweis. Ein Fehler beim Speichern des Berichts verändert weder den Exit-Code noch den protokollierten Migrationsstatus. JSON-Vorschauen und JSON-Statusausgaben bleiben von diesen Zusammenfassungen getrennt auf STDOUT.

PHP- und WP-CLI-Meldungen können bereits vor dem Laden des Plugins entstehen. Für eine frühere Sammlung kann der mitgelieferte Einstiegspunkt ausdrücklich geladen werden:

```sh
wp --require=/absoluter/pfad/wp-content/plugins/rrze-cli/migration-bootstrap.php rrze-migration wizard import
```

Alternativ denselben absoluten Pfad als `require` in einer geeigneten WP-CLI-Konfiguration hinterlegen. Der Einstiegspunkt greift nur bei `rrze-migration`; andere WP-CLI-Befehle behalten ihre normale Fehlerbehandlung. Meldungen vor der Verarbeitung von `--require`, PHP-Startfehler und Ausgaben fremder Fehlerhandler lassen sich damit nicht vollständig erfassen. Ein erneuter Lauf mit `--debug` zeigt die normalen Diagnosemeldungen; solche Ausgaben vor dem Teilen auf vertrauliche Inhalte prüfen.

## Automatisierte Aufrufe

Der Wizard verlangt interaktive Ein- und Ausgabe und akzeptiert keine automatische Zustimmung mit `--yes` oder unterdrückte Ausgabe mit `--quiet`. Zusätzlich zu `--plain` und `--verbose` unterstützt der Wizard `--site-id` für den Export; beim Import ist diese Option unzulässig. Für Skripte bleiben die expliziten Befehle verfügbar. Diese Beispiele setzen eine konfigurierte `RRZE_MIGRATION_RUN_DIR` voraus:

```sh
wp rrze-migration export all website.zip --site-id=5
wp rrze-migration import all incoming/website.zip --new_url=https://target.example.test/site/ --dry-run --format=json
wp rrze-migration import all incoming/website.zip --new_url=https://target.example.test/site/ --run-dir=/srv/private/rrze-migrations
```

Diese Befehle fragen nicht nach einer interaktiven Freigabe. `export all` unterstützt dieselbe ID-Auflösung und Kontextprüfung; die Rohbefehle `export tables` und `export users` werden weiterhin über `--url` gesteuert. Medien werden immer extern übertragen; `--uploads` und `--skip-uploads` sind entfernt. Paket-, Website-, Tabellen- und Benutzerprüfungen gelten unverändert. Laravel Prompts und seine Abhängigkeiten werden mit dem Plugin ausgeliefert. Ein Laravel-Projekt ist nicht erforderlich; PHP 8.2 bleibt die Mindestversion.
