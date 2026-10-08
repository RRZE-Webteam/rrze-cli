# Export und Import mit dem Migrationsassistenten

Der Assistent führt durch die Auswahl der Eingaben, zeigt den Migrationsplan und verlangt vor der Ausführung eine Bestätigung. Er verwendet dieselben Prüfungen und Abläufe wie die direkten Befehle. Ein Import legt ausschließlich eine neue Website an; eine vorhandene Website wird weder übernommen noch automatisch gelöscht.

## Start und Umgebung

Den Befehl im Hauptverzeichnis der betreffenden WordPress-Installation in einem interaktiven Terminal starten:

```sh
wp rrze-migration wizard
```

Ohne Unterauswahl fragt der Assistent nach `import` oder `export`. Am Anfang zeigt er das WordPress-Verzeichnis, die aktuelle Website mit ID und bei Multisite das aktuelle Netzwerk. Die globalen WP-CLI-Parameter, insbesondere `--url`, wählen diesen anfänglichen Kontext. Für den Export kann anschließend eine Website-ID derselben Multisite-Installation ausgewählt werden. Der Assistent wechselt nicht zwischen Servern und überträgt kein Paket auf einen anderen Rechner.

Die Fragen sind wie die übrigen Migrationsausgaben auf Englisch. Vorgaben direkt hinter einer Eingabefrage stehen in eckigen Klammern und gelten für Enter. `!quit` bricht an jeder Eingabe ab. ZIP-Importpfade beziehen sich auf das private Migrationsverzeichnis oder werden absolut außerhalb der Webverzeichnisse angegeben. Exportnamen enthalten ausschließlich den Dateinamen ohne Verzeichnis. Leerzeichen in Dateinamen werden ohne Shell-Auswertung verarbeitet; bei der interaktiven Eingabe keine zusätzlichen Anführungszeichen setzen.

Beide Abläufe fragen nach `Private migration directory outside web roots`; beim Export erfolgt vorher die Auswahl und Bestätigung der Quelle. Eine konfigurierte `RRZE_MIGRATION_RUN_DIR` dient als Vorgabe. Der Pfad muss absolut und privat sein und außerhalb von WordPress und `wp-content` liegen; andere Webserver dürfen ihn ebenfalls nicht veröffentlichen. Das Elternverzeichnis muss bereits existieren. Die Pfadprüfung und eine Vorschau legen dort noch keine Verzeichnisse an. Bei einem bestätigten Export oder einem vorbereiteten echten Import wird ein fehlendes Stammverzeichnis mit Modus `0700` angelegt.

## Exportieren

```sh
wp rrze-migration wizard export --site-id=5
```

Ohne `--site-id` fragt der Assistent auf Multisite nach `Source website ID`; die aktuelle Website-ID ist die Vorgabe. Der bisherige URL-Aufruf bleibt möglich und bestimmt diese Vorgabe:

```sh
wp rrze-migration wizard export --url=https://source.example.test/site/
```

Die ID muss eine positive ganze Zahl sein und zu einer vorhandenen Website gehören. Bei einer anderen Website wird WordPress in einem neuen WP-CLI-Prozess mit deren registrierter Adresse geladen, damit auch ihre Plugins, Rollen und Upload-Einstellungen aktiv sind. Erst nach Prüfung der geladenen ID folgt die Bestätigung. Eine falsche Zuordnung führt zum Abbruch. Wird zusätzlich `--url` angegeben, entscheidet die ausdrücklich ausgewählte ID über die Exportquelle. Bei einer Einzelinstallation wird die aktuelle Website bestätigt; `--site-id` ist dort nicht verfügbar.

1. Die angezeigte Zuordnung `Export source: ID 5 | URL: …` prüfen und `Export this website (yes/no) [no]` mit `yes` beantworten. Enter, `no`, `!quit` oder Eingabeende brechen vor der Anlage von Exportdateien ab. Bei einer falschen Quelle abbrechen und mit der richtigen ID neu starten.
2. Das private Migrationsverzeichnis bestätigen und einen ZIP-Dateinamen ohne Pfad wählen. Eine fehlende Endung `.zip` wird ergänzt. Jeder Export erhält einen eigenen Ordner `export-<UTC-Zeitstempel>-site-<ID>-<Zufallskennung>`; derselbe Dateiname überschreibt deshalb keinen älteren Export.
3. Bei Bedarf zusätzliche, ausschließlich dieser Website gehörende Tabellen angeben. Die automatische Auswahl entspricht `export all`; bei Hauptsites sind zusätzliche eigene Tabellen ausdrücklich zu benennen und fachlich zu prüfen.
4. Die Medienauswahl prüfen. Uploads sind vorausgewählt. Plugins und Themes werden separat im Ziel bereitgestellt.
5. Bei enthaltenen Uploads gegebenenfalls Verzeichnisse ausdrücklich ausschließen. Die Eingabe bleibt normalerweise leer. Für ein nicht zu migrierendes Plugin-Arbeitsverzeichnis wie `wp-migrate-db` genau diesen relativen Namen eingeben; mehrere Namen werden mit Komma getrennt. Es werden keine Verzeichnisse automatisch ausgelassen.
6. Den Plan mit Quelle, Tabellen, Ausgabeort, Medienauswahl und Ausschlüssen lesen. Die angezeigte vollständige Quell-URL wiederholen und anschließend `yes` eingeben.

Erst nach dieser Bestätigung werden der angezeigte Export-Unterordner und die ZIP-Datei angelegt. Der Ordner erhält Modus `0700`, die Datei `0600`. Abbrüche vor der Bestätigung hinterlassen keinen Exportordner; bei einem behandelten Exportfehler wird die unvollständige Ausgabe entfernt. Der Export enthält keine Benutzerpasswörter oder Sitzungstoken. Die ZIP-Datei anschließend über den betrieblich vorgesehenen Weg in ein privates Verzeichnis des Zielsystems übertragen, beispielsweise `<RRZE_MIGRATION_RUN_DIR>/incoming/website.zip`. Bei einem Import auf demselben System kann der angezeigte Exportpfad direkt verwendet werden.

Enthalten Uploads `.htaccess`, `index.php` oder andere nicht erlaubte Dateien, benennt die Fehlermeldung den betroffenen Paketpfad. Solche Schutz- oder Konfigurationsdateien auf der Quelle nicht löschen, um den Export zu ermöglichen. Ein ausdrücklich ausgewähltes Verzeichnis wird einschließlich seiner Unterverzeichnisse nur aus dem Paket ausgelassen; seine Quelldateien bleiben erhalten. Absolute Pfade, Pfadtraversal, symbolische Links und fehlende Verzeichnisse sind als Ausschluss unzulässig. Die Auswahl arbeitet mit vollständigen Verzeichnisnamen, nicht mit Wildcards.

Die Ausschlüsse stehen im Paketmanifest unter `excluded_upload_directories` und im späteren Importplan. Beim direkten Export lautet die entsprechende Option `--exclude-upload-dirs=wp-migrate-db` zusammen mit `--uploads`. Ein ähnlich benannter Ordner wie `wp-migrate-db-other` bleibt enthalten. Ein solches Paket ist keine vollständige Sicherung des ursprünglichen Upload-Verzeichnisses.

## Import zunächst prüfen

```sh
wp rrze-migration wizard import
```

Der Import benötigt eine Multisite. Der Assistent fragt zuerst nach dem privaten Migrationsverzeichnis und anschließend nach einer lesbaren Paketdatei, einer ausdrücklich angegebenen neuen Ziel-URL und gegebenenfalls Post-Metafeldern mit numerischen Benutzer-IDs. Eine bereits belegte Adresse wird zurückgewiesen; es gibt keinen Löschschritt. War diese Adresse bisher belegt, muss die alte Website vorher manuell in Network Admin gelöscht worden sein. Vor dieser Löschung bleibt eine unabhängige Sicherung erforderlich.

Gefundene ZIP-Dateien erscheinen in einer nummerierten Liste mit relativem Pfad, Änderungsdatum der Datei in UTC und Größe in MiB. Neuere Änderungen stehen zuerst; bei gleichem Datum entscheidet der Pfad. So bleiben gleich benannte Pakete in verschiedenen Unterverzeichnissen unterscheidbar. Beispielsweise wählt die Eingabe `2` den angezeigten Eintrag `[2]`. Der Assistent zeigt danach den vollständigen ausgewählten Pfad. Es gibt keine vorausgewählte Datei; Enter allein wählt kein Paket, ungültige Nummern führen zur erneuten Eingabe.

Die Suche berücksichtigt das private Stammverzeichnis und bis zu drei Unterverzeichnisebenen, darunter Exportordner, `incoming` und aufbewahrte Importkopien. Sie folgt keinen symbolischen Links und öffnet keine ZIP-Inhalte. Es werden höchstens 5000 Verzeichniseinträge untersucht und 50 ZIP-Dateien angezeigt. Wird eine Grenze erreicht oder ein Unterverzeichnis nicht lesbar, weist der Assistent auf die unvollständige Liste hin. Ein Listeneintrag bestätigt weder ein gültiges Paket noch den Erfolg eines früheren Imports; die bisherigen Paketprüfungen erfolgen nach der Auswahl.

Alternativ direkt einen Paketpfad wie `incoming/website.zip` oder `export-<Zeitstempel>-site-<ID>-<Kennung>/website.zip` eingeben. Ein absoluter privater Pfad ist ebenfalls möglich, auch bei leerer Liste oder einem noch nicht angelegten Migrationsverzeichnis. ZIP-Dateien innerhalb von WordPress oder `wp-content` werden abgelehnt, auch wenn ein Verzeichnislink dorthin führt. Alte Pakete zuerst selbst aus dem Webroot in private Ablage verschieben. Es gibt keinen stillen Rückgriff auf das WordPress-Verzeichnis, und die ursprüngliche Eingabedatei wird nicht automatisch verschoben oder gelöscht.

Bei `Next action (preview/import) [preview]` startet Enter die vollständige Vorprüfung. Sie zeigt die normalisierte Zieladresse, das Zielnetzwerk, die geschätzte Site-ID, Tabellen, Benutzeraktionen, Referenzfelder und Medien. Dabei entstehen keine Zielwebsite, Benutzerkonten, Zieltabellen, Ziel-Uploads oder dauerhaften Laufdaten. Die privaten Prüfdateien werden anschließend entfernt. WordPress und aktive Plugins werden wie bei anderen WP-CLI-Aufrufen geladen.

## Bei Upload-Einschränkungen ohne Medien fortfahren

Bei benutzerdefinierten Upload-Pfaden, `upload_dir`-Filtern oder alten Multisite-Upload-Layouts zeigt der Assistent die Einschränkung und fragt sowohl in der Vorschau als auch beim Import:

```text
Continue without uploads (yes/no) [no]:
```

Enter, `!quit` und Eingabeende brechen ab. Mit `yes` wird derselbe geprüfte Paketinhalt erneut geplant, diesmal ohne automatischen Medientransfer. Eine Vorschau bleibt eine Vorschau. Beim Import folgen weiterhin der vollständige Plan, die Bestätigung der Ziel-URL und die abschließende Zustimmung. Eine Zustimmung aus einem früheren Vorschau-Aufruf wird nicht gespeichert oder für einen späteren Import übernommen.

Der Plan kennzeichnet `Media transfer: SKIPPED`, nennt weiterhin den Medienumfang im Paket und gibt keinen vermeintlich geprüften Upload-Zielpfad an. Die Dateien müssen anschließend separat, beispielsweise mit `rsync`, übertragen werden. Den tatsächlichen Upload-Pfad und die Medien-URLs im Ziel prüfen und gegebenenfalls anpassen: Die allgemeine Ersetzung der Website-URL läuft weiter, die gesonderte Umschreibung von `wp-content/uploads/sites/<Quell-ID>` auf die neue Site-ID entfällt. Die Quelloptionen `upload_path` und `upload_url_path` werden weiterhin zurückgesetzt. Ein erfolgreicher Datenimport bestätigt weder den separaten Transfer noch die Funktionsfähigkeit der Medien.

Paketsicherheit und Prüfsummen gelten auch für enthaltene Medien weiterhin; das Archiv wird unverändert geprüft und vorübergehend entpackt. Belegte Zieladressen, vorhandene Tabellen und Mitgliedschaften sowie Dateien oder Links am Standard-Uploadpfad werden weiterhin abgelehnt. Individuelle Upload-Verzeichnisse werden in diesem Modus nicht aufgelöst oder auf Restbestände geprüft. Fremde WordPress-/Plugin-Hooks werden weiterhin geladen; dieser Modus isoliert deren Seiteneffekte nicht.

Nur die nicht unterstützte Upload-Konfiguration bietet diese Auswahl. Beschädigte Pakete, unzureichende Datenbankrechte oder andere Fehler brechen weiterhin ab. Entsteht die Einschränkung erst bei der erneuten Prüfung nach der Freigabe, stoppt der Import ebenfalls und benötigt einen neu geprüften Plan.

Die Abschlussmeldung nennt den erfolgreichen Datenimport und die ausstehenden Medienarbeiten. Im Journal steht `import_uploads: skipped`; `uploads.verified` bleibt `false`. Auch spätere Statusabfragen zeigen den Hinweis zur manuellen Übertragung und Prüfung.

## Import ausführen

Den Assistenten erneut starten und bei `Next action` ausdrücklich `import` wählen. Das am Anfang ausgewählte private Migrationsverzeichnis dient auch als Stammverzeichnis für den neuen Importlauf. Einrichtung und Aufbewahrung beschreibt die [Wiederherstellungsanleitung](migration-recovery.md).

Vor dem Bestätigen wird das Paket privat kopiert und geprüft. Der Plan bezieht sich auf genau diese Kopie. Er nennt die bestehenden WordPress-Konten, die eine neue Website-Mitgliedschaft erhalten, und die fehlenden Konten, die mit zufälligem lokalem Passwort angelegt werden. Konten, die nur von Beiträgen oder Kommentaren referenziert werden und keine Quellmitgliedschaft haben, erscheinen als `reference only; no site membership`; sie erhalten auch am Ziel keine Mitgliedschaft. Fehlende Identitäten für diese Kernreferenzen stoppen bereits die Vorprüfung. SSO-Identitäten werden nicht angelegt oder verändert. Fehlende Medien verlangen eine zusätzliche ausdrückliche Bestätigung; ein separater Transfer wird dadurch nicht als geprüft ausgegeben.

Enthält das Paket ausdrücklich ausgeschlossene Upload-Verzeichnisse, zeigt der Plan deren Namen. Der Wizard verlangt dafür eine zusätzliche Zustimmung mit Vorgabe `no`: Diese Verzeichnisse werden weder übertragen noch auf Vollständigkeit geprüft. Eine ausdrückliche Auswahl beim Export lockert keine Pfad-, Dateityp- oder Prüfsummenregel für die enthaltenen Dateien.

Die vollständig angezeigte Ziel-URL einschließlich Schema und abschließendem Pfad wiederholen. Danach fragt der Assistent, ob er die neue Website anlegen und den Plan ausführen soll. Nur `yes` stimmt zu; Enter bedeutet `no`. Eine abweichende URL bricht ab.

Nach der Bestätigung wird der Plan unter der Migrationssperre erneut geprüft. Ändern sich Zielressourcen, geschätzte Site-ID, Benutzeraktionen oder andere angezeigte Entscheidungen, stoppt der Import vor der Site-Anlage. Der Assistent muss dann mit einem neuen Plan gestartet werden. Der verfügbare Speicher darf sich verändern, muss aber weiterhin die Kapazitätsprüfung bestehen. Eine Änderung der ursprünglich angegebenen ZIP-Datei ersetzt nicht die bereits geprüfte private Kopie.

Während der Ausführung benennt die Konsole die laufenden Schritte. Erst nach der Ergebnisprüfung und Bereinigung erscheint die Erfolgsmeldung. Inhalte, Medien, Rollen und reale SSO-Anmeldung sind anschließend betrieblich abzunehmen.

## Abbrechen und Fehler untersuchen

Leere Bestätigung, falsche Bestätigungs-URL, `!quit` und Eingabeende führen zu einem Fehlerstatus statt zu einer Erfolgsmeldung. Bei vorhandenem PCNTL werden während der Eingaben auch `Ctrl-C` und `SIGTERM` kontrolliert behandelt. Nach Beginn der Ausführung gelten die Abbruchregeln aus Paket 5: Ein laufender Unterprozess muss zunächst zurückkehren; bereits erfolgte Änderungen bleiben bestehen.

Wurde die Importprüfung bereits vorbereitet, bleiben Paketkopie und Journal auch nach einer abgelehnten Bestätigung erhalten. Das Journal meldet in diesem Fall `failed`, keine Site-ID und die abgeschlossene Arbeitsverzeichnis-Bereinigung. Das ist kein teilweise angelegtes Ziel. Nach einem späteren Ausführungsfehler kann hingegen bereits eine neue Website existieren. Laufkennung und Status unterscheiden diese Fälle:

```sh
wp rrze-migration status RUN_ID --run-dir=/srv/private/rrze-migrations
```

Die Hinweise zu harten Prozessabbrüchen, noch laufenden Kindprozessen und manueller Wiederherstellung gelten unverändert. Der Assistent bietet weder Wiederaufnahme noch automatisches Löschen oder Zurückspielen globaler Tabellen an.

## Automatisierte Aufrufe

Der Wizard verlangt interaktive Ein- und Ausgabe und akzeptiert keine automatische Zustimmung mit `--yes` oder unterdrückte Ausgabe mit `--quiet`. Die einzige Migrationsoption ist `--site-id` für den Export; beim Import ist sie unzulässig. Für Skripte bleiben die expliziten Befehle verfügbar. Diese Beispiele setzen eine konfigurierte `RRZE_MIGRATION_RUN_DIR` voraus:

```sh
wp rrze-migration export all website.zip --site-id=5 --uploads
wp rrze-migration import all incoming/website.zip --new_url=https://target.example.test/site/ --dry-run --format=json
wp rrze-migration import all incoming/website.zip --new_url=https://target.example.test/site/ --run-dir=/srv/private/rrze-migrations
```

Diese Befehle fragen nicht nach einer interaktiven Freigabe. `export all` unterstützt dieselbe ID-Auflösung und Kontextprüfung; die Rohbefehle `export tables` und `export users` werden weiterhin über `--url` gesteuert. Für eine ausdrücklich gewünschte manuelle Medienübertragung `--skip-uploads` sowohl zur Vorschau als auch zum Import hinzufügen. Ohne diese Option bleibt die Upload-Einschränkung im direkten Befehl ein Fehler. Paket-, Website-, Tabellen- und Benutzerprüfungen gelten unverändert. Die Oberfläche benötigt keine zusätzliche Prompt-Bibliothek; Ein- und Ausgabe erfolgen über die vorhandene PHP-/WP-CLI-Laufzeit.
