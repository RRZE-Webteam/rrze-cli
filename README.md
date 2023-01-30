# RRZE-CLI

WP-CLI Erweiterung für die CMS-Verwaltung des RRZE.

## Anforderungen

-   PHP >= 8.0
-   WP-CLI >= 2.7.1

## Migration

Diese WP-CLI-Erweiterung macht den Prozess der Migration von Websites von einzelnen Wordpress-Instanzen zu einer Multisite-Instanz (oder umgekehrt) viel einfacher. Es exportiert alles in ein ZIP-Paket, das verwendet werden kann, um es automatisch in die gewünschte Multisite-Installation zu importieren.

### Verwendung

Der Befehl `rrze-migration export` exportiert eine ganze Website in ein Zip-Paket.

```
$ wp rrze-migration export all website.zip --plugins --themes --uploads
```

Der obige Befehl exportiert Benutzer, Tabellen, Plugins-Ordner, Themes-Ordner und den Uploads-Ordner in eine ZIP-Datei, die man auf der Multisite-Instanz migrieren kann, um sie mit dem Befehl `import all` zu importieren. Die optionalen Flags `--plugins --themes --uploads` fügen den Plugins-Ordner, den Themes-Ordner bzw. den Uploads-Ordner zur ZIP-Datei hinzu.

Man kann auch Websites aus einer Multisite-Instanz exportieren, man muss dazu den Parameter `--blog_id` übergeben. Bspw:

```
$ wp rrze-migration export all website.zip --blog_id=2
```

Der Befehl `rrze-migration import` kann verwendet werden, um eine Website aus einem ZIP-Paket zu importieren.

```
$ wp rrze-migration import all website.zip
```

Beim Importieren in eine Multisite-Instanz wird eine neue Website innerhalb der Multisite-Instanz erstellt, basierend auf der Website, die man gerade exportiert hat. Beim Importieren in eine einzelne Installation wird die aktuelle Website mit der exportierten Website überschrieben.

Der Befehl `rrze-migration import all` kümmert sich um alles, was getan werden muss, wenn eine Website in der Multisite-Instanz migrieren wird (Ersetzen von Tabellenpräfixen, Aktualisieren von `post_author`-IDs usw.).

Wenn man eine neue URL für die zu importierende Website einrichten muss, kann man diese an den Befehl `rrze-migration import all` übergeben.

```
$ wp rrze-migration import all website.zip --new_url=new-website-domain
```

Der Befehl `rrze-migration import` unterstützt auch den Parameter `--mysql-single-transaction`, der den SQL-Export in eine einzige Transaktion umschließt, um alle Änderungen aus dem Import auf einmal festzuschreiben und zu verhindern, dass der Schreibvorgang den Datenbankserver überlastet.

Man kann auch `--blog_id` an den Befehl `import all` übergeben, in diesem Fall überschreibt der Import eine vorhandene Website.

```
$ wp rrze-migration import all website.zip --new_url=new-website-domain --blog_id=2
```

In einigen Sonderfällen ist es möglich, `rrze-migration export` nicht alle benutzerdefinierten Tabellen erkennen kann, während eine Website in eine Multisite-Instanz exportiert wird. Wenn man also nicht standardmäßige Tabellen migrieren muss, kann man das Parameter `--tables` oder `--custom-tables` verwenden. Bspw:

```
$ wp rrze-migration export all website.zip --blog_id=1 --custom-tables=custom_table_1,custom_table_2
```

Wenn man `--tables` übergeben, werden nur die übergebenen Tabellen exportiert. Wenn man es also verwendet, muss man sicher stellen, dass alle Tabellen übergebit, die man exportieren möchtet, einschließlich der Standardtabellen des WordPress.

### Anmerkungen

Wenn die Themes und die Plugins auf WordPress-Art erstellt wurden, sollte man nach der Migration keine größeren Probleme haben. Man muss daran denken, dass bei einigen Themes Inkompatibilitätsprobleme auftreten können (bspw. fest codierte Links wie '/kontakt' usw.). Abhängig von der Codebasis der Website, die man migriert, muss man möglicherweise einige Anpassungen an dem Code vornehmen.
