# Export und Import mit dem Migrationsassistenten

Der Assistent führt durch die Auswahl der Eingaben, zeigt den Migrationsplan und verlangt vor der Ausführung eine Bestätigung. Er verwendet dieselben Prüfungen und Abläufe wie die direkten Befehle. Ein Import legt ausschließlich eine neue Website an; eine vorhandene Website wird weder übernommen noch automatisch gelöscht.

## Start und Umgebung

Den Befehl im Hauptverzeichnis der betreffenden WordPress-Installation in einem interaktiven Terminal starten:

```sh
wp rrze-migration wizard
```

Ohne Unterauswahl fragt der Assistent nach `import` oder `export`. Am Anfang zeigt er das WordPress-Verzeichnis, die aktuelle Website mit ID und bei Multisite das aktuelle Netzwerk. Die globalen WP-CLI-Parameter, insbesondere `--url`, wählen diesen Kontext vor dem Start. Der Assistent wechselt nicht zwischen Servern und überträgt kein Paket auf einen anderen Rechner.

Die Fragen sind wie die übrigen Migrationsausgaben auf Englisch. Werte in eckigen Klammern sind Vorgaben für Enter. `!quit` bricht an jeder Eingabe ab. Pfade beziehen sich auf das angezeigte WordPress-Verzeichnis oder können absolut angegeben werden. Leerzeichen in Dateinamen werden ohne Shell-Auswertung verarbeitet; bei der interaktiven Eingabe keine zusätzlichen Anführungszeichen setzen.

## Exportieren

```sh
wp rrze-migration wizard export --url=https://source.example.test/site/
```

1. Prüfen, dass die angezeigte Website tatsächlich die Quelle ist. Bei einem falschen Kontext abbrechen und mit dem richtigen `--url` neu starten.
2. Einen neuen ZIP-Dateinamen wählen. Bestehende Dateien werden abgelehnt.
3. Bei Bedarf zusätzliche, ausschließlich dieser Website gehörende Tabellen angeben. Die automatische Auswahl entspricht `export all`; bei Hauptsites sind zusätzliche eigene Tabellen ausdrücklich zu benennen und fachlich zu prüfen.
4. Die Medienauswahl prüfen. Uploads sind vorausgewählt. Plugins und Themes werden separat im Ziel bereitgestellt.
5. Den Plan mit Quelle, Tabellen, Ausgabeort und Medienauswahl lesen. Die angezeigte vollständige Quell-URL wiederholen und anschließend `yes` eingeben.

Erst nach dieser Bestätigung wird die Ausgabedatei angelegt. Der Export enthält keine Benutzerpasswörter oder Sitzungstoken. Die ZIP-Datei anschließend über den betrieblich vorgesehenen Weg auf das Zielsystem übertragen.

## Import zunächst prüfen

```sh
wp rrze-migration wizard import
```

Der Import benötigt eine Multisite. Der Assistent fragt nach einer lesbaren Paketdatei, einer ausdrücklich angegebenen neuen Ziel-URL und gegebenenfalls Post-Metafeldern mit numerischen Benutzer-IDs. Eine bereits belegte Adresse wird zurückgewiesen; es gibt keinen Löschschritt. War diese Adresse bisher belegt, muss die alte Website vorher manuell in Network Admin gelöscht worden sein. Vor dieser Löschung bleibt eine unabhängige Sicherung erforderlich.

Bei `Next action (preview/import) [preview]` startet Enter die vollständige Vorprüfung. Sie zeigt die normalisierte Zieladresse, das Zielnetzwerk, die geschätzte Site-ID, Tabellen, Benutzeraktionen, Referenzfelder und Medien. Dabei entstehen keine Zielwebsite, Benutzerkonten, Zieltabellen, Ziel-Uploads oder dauerhaften Laufdaten. Die privaten Prüfdateien werden anschließend entfernt. WordPress und aktive Plugins werden wie bei anderen WP-CLI-Aufrufen geladen.

## Import ausführen

Den Assistenten erneut starten und bei `Next action` ausdrücklich `import` wählen. Ein privates dauerhaftes Laufverzeichnis außerhalb der Webverzeichnisse angeben; eine konfigurierte `RRZE_MIGRATION_RUN_DIR` wird als Vorgabe angezeigt. Einrichtung und Aufbewahrung beschreibt die [Wiederherstellungsanleitung](migration-recovery.md).

Vor dem Bestätigen wird das Paket privat kopiert und geprüft. Der Plan bezieht sich auf genau diese Kopie. Er nennt die bestehenden WordPress-Konten, die nur eine neue Website-Mitgliedschaft erhalten, und die fehlenden Konten, die mit zufälligem lokalem Passwort angelegt werden. SSO-Identitäten werden nicht angelegt oder verändert. Fehlende Medien verlangen eine zusätzliche ausdrückliche Bestätigung; ein separater Transfer wird dadurch nicht als geprüft ausgegeben.

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

Der Wizard verlangt interaktive Ein- und Ausgabe und akzeptiert keine automatische Zustimmung mit `--yes`, keine unterdrückte Ausgabe mit `--quiet` und keine zusätzlichen Migrationsoptionen. Für Skripte bleiben die expliziten Befehle verfügbar:

```sh
wp rrze-migration import all website.zip --new_url=https://target.example.test/site/ --dry-run --format=json
wp rrze-migration import all website.zip --new_url=https://target.example.test/site/ --run-dir=/srv/private/rrze-migrations
```

Diese Befehle fragen nicht nach einer interaktiven Freigabe. Sie verwenden weiterhin alle Paket-, Ziel-, Benutzer- und Ausführungsprüfungen. Die Oberfläche benötigt keine zusätzliche Prompt-Bibliothek; Ein- und Ausgabe erfolgen über die vorhandene PHP-/WP-CLI-Laufzeit.
