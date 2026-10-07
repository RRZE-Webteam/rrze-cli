# Umfang und Sicherheitsregeln der Migration

Dieses Dokument definiert Arbeitspaket 1 der Überarbeitung von rrze-cli. Es dient als Grundlage für Implementierung, Tests und Betriebsabnahme. Die verbindliche Zielregel lautet: Ein Import legt ausschließlich eine neue Website in einer Multisite an. Eine vorhandene Zielwebsite muss vorher außerhalb des Imports manuell in der Multisite-Verwaltung gelöscht worden sein.

Stand: 5. Oktober 2026. Die Zielregel, die unten beschriebenen betrieblichen SSO-Vorgaben und die lokale Neuanlage fehlender WordPress-Benutzer mit zufälligem Passwort sind festgelegt. Die ausdrücklich als Vorschlag bezeichneten Festlegungen zu Quellen, Benutzerkonflikten, Zugangsdaten, Erweiterungen und Medien sind noch abzustimmen. Die beschriebenen Schutzmaßnahmen sind Anforderungen an die Überarbeitung und noch keine Zusicherung des aktuellen Codes.

## Verbindliche Regeln für das Ziel

| Kennung | Regel |
| --- | --- |
| Z1 | Das Importziel ist immer eine WordPress-Multisite. Ein Import in eine Single-Site wird vor Änderungen an der Installation abgelehnt. |
| Z2 | Der Import legt die Zielwebsite selbst neu an. Er übernimmt keine bereits vorhandene Website, auch keine leere oder zuvor manuell neu angelegte Website. |
| Z3 | Ist die gewünschte Zieladresse belegt, wird der Import vor jeder Änderung an der Installation abgebrochen. Der Import löscht, ersetzt oder leert die vorhandene Website nicht. |
| Z4 | Das manuelle Löschen einer bisherigen Zielwebsite in der Multisite-Verwaltung ist eine externe Voraussetzung. Es gibt keinen Löschschritt im Wizard und keinen Schalter, der ein Überschreiben erlaubt. |
| Z5 | Ein vorhandener Site-Eintrag blockiert das Ziel unabhängig von seinem Status, etwa aktiv, archiviert, deaktiviert oder als gelöscht markiert. Ein Statuswechsel allein gibt das Ziel nicht frei. |
| Z6 | Die neu erzeugte Ziel-ID wird von WordPress vergeben. Quell-ID, frühere Ziel-ID und Paketangaben dürfen keine bestehende Zielwebsite oder deren Tabellen auswählen. |
| Z7 | Diese Regeln gelten auch für direkte Teilbefehle und nichtinteraktive Aufrufe. Ein Bestätigungsparameter darf sie nicht umgehen. |

Die Zielidentität ergibt sich aus der tatsächlichen Zielinstallation, dem Zielnetzwerk und der normalisierten Domain mit Website-Pfad. Bei Unterverzeichnisinstallationen ist der Pfad Teil der Identität. Eine Änderung von HTTP zu HTTPS gibt eine vorhandene Website nicht zum Import frei. Mehrdeutige Zuordnungen zwischen Netzwerken oder Domain-Aliasen verhindern die Ausführung, bis das Ziel eindeutig bestimmt ist.

Eine bisher unbenutzte Zieladresse benötigt keinen vorherigen Löschvorgang. War die Adresse bereits belegt, prüft der Import den aktuellen Zustand; er kann daraus nicht nachweisen, wer die vorherige Website auf welchem Weg gelöscht hat.

## Betriebliche Vorgaben zur Anmeldung

Für Production sind folgende Rahmenbedingungen durch die Projektvorgabe bestätigt:

- Die Anmeldung erfolgt über `rrze-sso`.
- `user_login` ist immer die Nutzerkennung, mit der sich die Person über SSO authentifiziert. Diese Kennung ist die maßgebliche Identität für die Benutzerzuordnung.
- `user_email` ist immer die Company-E-Mail-Adresse. Die Adresse dient als Kontaktangabe und zur Konsistenzprüfung; eine übereinstimmende Adresse allein rechtfertigt keine Zuordnung zu einer anderen Nutzerkennung.

Damit ersetzt die Zuordnung über die SSO-Kennung den bisherigen Vorschlag, Login und E-Mail als gemeinsamen Identitätsschlüssel zu behandeln. Die numerische WordPress-Benutzer-ID bleibt installationsabhängig und muss beim Import anhand der Kennung zugeordnet werden. Quell- und Zielsystem müssen denselben Kennungsraum verwenden; notwendige Abweichungen sind ausdrücklich zu klären. Eine freie Änderung der Kennung durch den Import oder den Export ist nicht vorgesehen.

Die Bedeutung von `user_pass` in der konkreten Production-Datenbank wurde nicht geprüft. Im lokal vorhandenen `rrze-sso` erzeugt `Authenticate::authenticate()` beim automatischen Anlegen eines Benutzers ein zufälliges Passwort und übergibt es an `wp_insert_user()`; der lokale WordPress-Code speichert dafür einen Passwort-Hash. `Authenticate::loaded()` entfernt die üblichen Loginprüfungen für Benutzername beziehungsweise E-Mail mit Passwort. Dieser Pfad wird laut `Main::loaded()` bei aktiviertem `force_sso` und verfügbarer SimpleSAML-Anbindung geladen.

Dieser Codebefund erklärt, warum ein WordPress-Passwort-Hash existieren kann, ohne das SSO-Passwort der Person abzubilden. Er belegt weder leere oder harmlose Werte in `user_pass` noch eine identische Version und Konfiguration auf Production. Dafür werden keine echten Passwortwerte benötigt; zu prüfen sind die eingesetzte Version, Konfiguration und die Anmeldewege.

## Szenarienmatrix

Die Freigabe in dieser Tabelle beschreibt den geplanten Funktionsumfang. Sie ist keine Freigabe der aktuellen Implementierung für den Produktivbetrieb.

| Quelle oder Ausgangslage | Ziel | Festlegung |
| --- | --- | --- |
| Single-Site | Neue Website in einer Multisite | Zur Unterstützung vorgeschlagen. |
| Untersite einer Multisite | Neue Website in derselben oder einer anderen Multisite | Zur Unterstützung vorgeschlagen; Quelle und Ziel müssen getrennte Site-Identitäten haben. |
| Hauptsite einer Multisite | Neue Untersite in einer Multisite | Zur Unterstützung vorgeschlagen; nur Daten der Quellwebsite, keine Übernahme des Quellnetzwerks. |
| Beliebige unterstützte Quelle | Bereits vorhandene Website | Ausgeschlossen, auch bei leerem Inhalt. |
| Beliebige unterstützte Quelle | Vorhandene archivierte, deaktivierte oder als gelöscht markierte Website | Ausgeschlossen, solange der Site-Eintrag existiert. |
| Beliebige unterstützte Quelle | Manuell gelöschte Zielwebsite, Adresse frei | Neuanlage zulässig, sofern alle übrigen Prüfungen erfolgreich sind. Die alte Site-ID wird nicht gezielt wiederverwendet. |
| Beliebige Quelle | Single-Site oder vorhandene Hauptsite des Zielnetzwerks | Ausgeschlossen. |
| Wiederholung eines erfolgreichen Imports | Im ersten Lauf angelegte Website | Abbruch wegen vorhandenem Ziel. |
| Wiederholung nach einem teilweise ausgeführten Import | Im abgebrochenen Lauf angelegte Website | Abbruch wegen vorhandenem Ziel. Keine automatische Übernahme oder Löschung; die Website muss vor einem neuen Import manuell gelöscht werden. |
| Beliebige unterstützte Quelle | Freie Adresse, aber Konflikte mit vorhandenen Tabellen oder Dateien | Abbruch vor dem Überschreiben betroffener Ressourcen. Keine automatische Bereinigung fremder oder nicht eindeutig zuordenbarer Reste. |

## Grenzen der Änderungen

Die folgende Abgrenzung ist der Vorschlag für den ersten abgesicherten Funktionsumfang. Die Verbote des Überschreibens und des automatischen Löschens bestehender Websites gelten unabhängig von den noch offenen Detailentscheidungen.

| Ressource | Vorgesehener Zugriff | Schutzgrenze |
| --- | --- | --- |
| Quellwebsite | Lesen und Exportieren ihrer Daten. | Keine Änderungen an Quelldaten durch den Export. Ein bestehendes Exportarchiv wird nicht stillschweigend ersetzt. |
| Neue Zielwebsite | Site-Eintrag anlegen und Inhalte, Optionen, Beziehungen und Medien importieren. | Schreibzugriffe sind an die vom aktuellen Lauf neu angelegte Website gebunden. |
| Tabellen der neuen Zielwebsite | Initialisieren und mit den zugehörigen Quelldaten befüllen. | Nur exakt bestimmte Zieltabellen. Vom aktuellen Lauf frisch angelegte Standardtabellen dürfen befüllt oder ersetzt werden; vorbestehende oder fremde Tabellen nicht. |
| Benutzerdefinierte Tabellen | Explizit deklarierte Tabellen mit eindeutigem Bezug zur Quellwebsite übernehmen. | Globale oder gemeinsam genutzte Tabellen brauchen eine gesonderte fachliche Lösung und gehören zunächst nicht zum unterstützten Umfang. |
| Andere Websites einschließlich Hauptsite | Für notwendige Konfliktprüfungen lesen. | Keine Änderung ihrer Inhalte, Optionen, Rollen, Dateien oder Aktivierungen. |
| Globale Benutzer | Vorhandene Benutzer anhand der SSO-Nutzerkennung zuordnen; die Firmenadresse auf Konsistenz prüfen. Mitgliedschaft und Rolle ausschließlich für die neue Website ergänzen. Fehlende WordPress-Benutzer werden mit einem neuen zufälligen lokalen Passwort angelegt. | Keine Zuordnung allein anhand der E-Mail. Keine Änderung vorhandener Passwörter, Profildaten, SSO-Metadaten, Anwendungskennwörter, Superadminrechte oder Rollen anderer Websites. Der Import legt keine Identitäten im SSO-System an. |
| Netzwerkdaten | Die für die reguläre Neuanlage benötigten Site-Datensätze und zugehörigen Verwaltungsdaten ergänzen. | Kein Import globaler SQL-Tabellen und keine Übernahme von Netzwerkeinstellungen aus dem Paket. |
| Plugins und Themes | Im Ziel zuvor bereitgestellte Erweiterungen prüfen und für die neue Website gemäß Migrationsplan verwenden. | Vorgeschlagen: keine Installation oder Aktualisierung aus dem Paket und keine netzwerkweite Aktivierung oder Deaktivierung durch den Import. |
| Medien | Dateien ausschließlich in den geprüften Uploadbereich der neuen Website übertragen. | Keine Übernahme fremder Uploadverzeichnisse; Dateikonflikte werden nicht stillschweigend überschrieben oder ignoriert. |
| Arbeitsdateien und Protokolle | In einem privaten Arbeitsverzeichnis des jeweiligen Laufs anlegen. | Außerhalb öffentlich erreichbarer Verzeichnisse; sensible Daten weder in Konsolenausgaben noch in Protokollen veröffentlichen. |

Auch SQL-Import und URL-Ersetzungen müssen diese Grenzen einhalten. Das bloße Ersetzen eines Tabellenpräfixes stellt keine Begrenzung des SQL-Zugriffs sicher. Paketangaben bestimmen weder Netzwerk, Ziel-ID noch Schreibpfade ungeprüft. Prüfsummen helfen, beschädigte Pakete zu erkennen; sie belegen nicht die Vertrauenswürdigkeit eines Pakets.

Der vorgesehene Betrieb verwendet Pakete aus kontrollierten Exporten. Frei bezogene SQL-Dateien oder Erweiterungen sind nicht automatisch vertrauenswürdig. Aktive Erweiterungen können durch WordPress-Hooks weitere Änderungen oder externe Aktionen auslösen. Unterstützte Erweiterungen und ihre Nebenwirkungen müssen deshalb in der Test- und Betriebsabnahme berücksichtigt werden.

## Vorgeschlagene Regeln für Benutzer und Erweiterungen

**Benutzerzuordnung:** Ausgangspunkt ist ausschließlich die SSO-Nutzerkennung in `user_login`. Vorgeschlagen wird eine blockierende Konsistenzprüfung der Firmenadresse vor jeder Änderung. Das konkrete Vorgehen ist in der folgenden Tabelle beschrieben. Die vollständige Zuordnung wird vor dem Anlegen der Zielwebsite geprüft.

| Befund im Zielnetzwerk | Vorgeschlagenes Verhalten |
| --- | --- |
| Dieselbe Kennung existiert eindeutig und die Firmenadresse stimmt überein. | Vorhandene WordPress-ID verwenden; globale Benutzerdaten unverändert lassen und nur Mitgliedschaft und Rolle für die neue Website ergänzen. |
| Dieselbe Kennung existiert, aber die Firmenadresse weicht ab. | Als Konsistenzkonflikt vor Änderungen stoppen und fachlich klären. Weder die Kennung wechseln noch die globale E-Mail-Adresse automatisch überschreiben. Eine möglicherweise legitime Adressänderung wird nicht als zweite Identität behandelt. |
| Die Kennung fehlt, aber die Firmenadresse gehört bereits zu einer anderen Kennung. | Konflikt melden und stoppen; keine Zusammenführung oder Zuordnung anhand der Adresse. |
| Kennung und Firmenadresse sind im Ziel noch nicht vorhanden. | Festgelegt: Der Import legt ein WordPress-Konto mit zufälligem lokalem Passwort an. Das erzeugt keinen SSO-Account und beweist keine erfolgreiche SSO-Anmeldung. |
| Kennung oder Firmenadresse fehlt, ist ungültig oder die Quelldaten sind widersprüchlich. | Vorprüfung abbrechen; keine erfundene Ersatzkennung oder Dummy-Adresse erzeugen. |

Die Validierung berücksichtigt die tatsächlich eingesetzte SSO-Kennungsbildung und deren Regeln zur Groß- und Kleinschreibung. Im lokalen `rrze-sso` kann ein konfigurierter Domain-Scope Bestandteil der Kennung sein. Der Export oder Import darf solche Bestandteile nicht eigenmächtig hinzufügen oder entfernen. Für den SSO-Ablauf ist deshalb vorgeschlagen, Änderungen durch `--usersuffix` auszuschließen; eine notwendige Überführung älterer Kennungen braucht eine gesondert geprüfte Zuordnung.

**Passwörter und andere Zugangsdaten:** Vorgeschlagen wird, im SSO-Migrationsformat `user_pass`, `_application_passwords`, Sitzungstoken und Rücksetzschlüssel nicht zu exportieren und aus älteren Paketen nicht in Benutzerkonten zu übernehmen. Das gilt ebenso für benutzerdefinierte Metadaten und alternative Exportpfade; diese dürfen die Ausschlüsse nicht umgehen. Bestehende Werte im Ziel bleiben unverändert. Neue WordPress-Benutzer erhalten gemäß der bestätigten Vorgabe ein neues zufälliges lokales Passwort statt des Quellwerts. Es werden keine Passwörter protokolliert, ausgegeben oder versendet und keine SSO-Konten oder SSO-Zugangsdaten angelegt.

**SSO-Abnahme:** Version und wirksame Konfiguration von `rrze-sso` einschließlich Kennungsbildung müssen zur Zielumgebung passen. Ein aktives Plugin allein belegt keinen funktionierenden SSO-Ablauf. In der vorgesehenen Testumgebung werden Anmeldung und Rollen für einen vorhandenen Benutzer sowie für einen neu bereitgestellten WordPress-Benutzer geprüft. SSO-Metadaten und Berechtigungsattribute werden nicht pauschal aus dem Paket kopiert; ihr notwendiger Umfang ist anhand der Integration festzulegen. Die Migration ändert die globale SSO-Konfiguration nicht.

**Berechtigungen:** Rollen dürfen nur für die neue Website vergeben werden. Unbekannte Rollen sowie Metadaten mit Bezug zu anderen Websites oder zum Netzwerk werden nicht ungeprüft übernommen. Das Anlegen neuer Benutzer darf keine Superadminrechte übertragen. Eine Website-Rolle ersetzt nicht die Authentifizierung über SSO.

**Plugins und Themes:** Als Ausgangspunkt werden die benötigten Versionen im Ziel vorab durch die Administration bereitgestellt. Fehlende oder nicht unterstützte Abhängigkeiten blockieren den Import. Ein bereits netzwerkweit aktives Plugin wird als vorhandene Abhängigkeit geprüft; sein Aktivierungsstatus wird nicht geändert. Ein nur netzwerkweit nutzbares Plugin muss außerhalb des Imports bereitgestellt und aktiviert werden.

**Medien:** Ein erfolgreicher Gesamtimport setzt die vereinbarte Medienübertragung voraus. Enthält das Paket keine Medien, muss ein gesonderter Transfer ausdrücklich Teil des Plans sein und vor Abschluss verifiziert werden. Die konkrete Anbindung, beispielsweise über rsync, ist noch festzulegen.

## Ablauf und Verantwortung

1. Die Administration erstellt und prüft das Exportpaket. Vor dem manuellen Löschen einer vorhandenen Website muss eine unabhängige, wiederherstellbare Sicherung ihrer benötigten Daten vorliegen. Das betrifft besonders Migrationen innerhalb derselben Installation.
2. Falls die Zieladresse belegt ist, löscht die Administration die bisherige Zielwebsite manuell in der Multisite-Verwaltung. Das Werkzeug führt diese Aktion nicht aus.
3. Der Import prüft Paket, Ziel, Zuordnungen, Abhängigkeiten und Ressourcen und erstellt einen Plan. Bis zu dessen Freigabe entstehen keine Änderungen an der WordPress-Installation; private Arbeitsdateien und Prüfprotokolle sind zulässig.
4. Unmittelbar vor der Neuanlage prüft der Import die Zielbedingungen erneut unter einer geeigneten Sperre. Eine inzwischen belegte Adresse oder eine konkurrierende Anlage darf nicht zu einer Übernahme des fremden Ziels führen.
5. Der Import legt eine neue Website an und protokolliert deren tatsächliche ID sowie die zu diesem Lauf gehörenden Ressourcen. Weitere Schreibschritte bleiben auf diesen Umfang begrenzt.
6. Erst nach Prüfung der vereinbarten Daten, Zuordnungen und Medien meldet der Lauf Erfolg. Ein kritischer Fehler stoppt die Verarbeitung mit einem Fehlerstatus und einem nachvollziehbaren Bericht über bereits ausgeführte Schritte.

Die Wiederherstellung einer vorab manuell gelöschten Website ist ein eigener administrativer Vorgang aus der Sicherung. Ein späterer Importabbruch macht diesen früheren Löschvorgang nicht rückgängig. Bleibt eine im fehlgeschlagenen Lauf neu angelegte Website zurück, wird sie nicht automatisch gelöscht. Globale Benutzer werden bei Aufräumarbeiten nicht pauschal entfernt, auch wenn sie während des Imports neu angelegt wurden.

## Abnahmekriterien für Ziel und Benutzerzuordnung

Diese Kriterien werden in den folgenden Arbeitspaketen als Tests umgesetzt. Sie sind hier spezifiziert, noch nicht erfolgreich ausgeführt.

| Kennung | Ausgangslage und Aktion | Erwartetes Ergebnis |
| --- | --- | --- |
| A1 | Import auf einer Single-Site starten. | Fehlerstatus vor jeder Änderung an der Installation. |
| A2 | Import auf eine vorhandene Zieladresse starten; alle relevanten Site-Status durchspielen. | Fehlerstatus; vorhandener Site-Eintrag, Daten, Mitgliedschaften und Dateien bleiben erhalten und unverändert. |
| A3 | Import auf eine vorhandene, inhaltlich leere Website starten. | Gleiche Ablehnung wie bei einer befüllten Website. |
| A4 | Eine frühere Zielwebsite im isolierten Test manuell löschen, dann ein gültiges Paket importieren. | Neue Website mit der von WordPress vergebenen ID; keine gezielte Wiederverwendung der alten ID oder alter Ressourcen. |
| A5 | Ein gültiges Paket auf eine bisher freie Adresse importieren. | Neuanlage ohne Forderung nach einem historischen Löschvorgang. |
| A6 | Quell-ID oder Paketpräfix entspricht einer anderen vorhandenen Website des Zielsystems. | Bestehende Website bleibt unverändert; Ziel-ID und Schreibumfang werden aus der tatsächlichen Neuanlage abgeleitet. |
| A7 | Dieselbe Zielidentität mit anderem URL-Schema oder gleichwertiger Schreibweise angeben. | Ein vorhandenes Ziel wird weiterhin als belegt erkannt. Bei Unterverzeichnisinstallationen wird der Website-Pfad korrekt berücksichtigt. |
| A8 | Zwei Importe für dasselbe Ziel starten oder das Ziel zwischen Vorprüfung und Ausführung anderweitig anlegen. | Höchstens ein Lauf legt das Ziel an; kein Lauf übernimmt oder verändert eine von anderer Seite angelegte Website. |
| A9 | Nach Erfolg oder nach einem Abbruch mit bereits angelegter Zielwebsite erneut importieren. | Fehlerstatus wegen vorhandenem Ziel; kein automatisches Löschen, Überschreiben oder Fortsetzen. |
| A10 | Vorbestehende Tabellen oder Dateien kollidieren mit den vorgesehenen Schreibzielen. | Abbruch vor Zugriffen, die diese Ressourcen verändern würden. |
| A11 | Direkte Teilbefehle oder Bestätigungsparameter für ein bestehendes Ziel verwenden. | Kein Umgehen der Zielregeln. Teilbefehle dürfen ausschließlich einen nachweislich zum aktuellen Lauf gehörenden neuen Zielkontext verändern. |
| A12 | Gültigen Import mit einer unbeteiligten Kontrollwebsite und bestehenden globalen Benutzern durchführen. | Kontrollwebsite bleibt unverändert. Bei bestehenden Benutzern ändern sich ausschließlich die ausdrücklich vorgesehenen Mitgliedschaften und Rollen der neuen Website. |
| A13 | Dieselbe SSO-Kennung und Firmenadresse liegen mit unterschiedlichen WordPress-IDs in Quelle und Ziel vor. | Die vorhandene Ziel-ID wird verwendet und alle unterstützten Benutzerreferenzen werden zugeordnet. Kennung, Profildaten, Passwörter, Anwendungskennwörter und SSO-Metadaten des vorhandenen Benutzers bleiben unverändert. |
| A14 | Die Firmenadresse stimmt überein, die SSO-Kennungen unterscheiden sich. | Keine automatische Zuordnung oder Zusammenführung anhand der Adresse; Konflikt vor Änderungen melden. |
| A15 | Dieselbe SSO-Kennung hat in Quelle und Ziel unterschiedliche Firmenadressen. | Gemäß vorgeschlagener Konfliktregel vor Änderungen stoppen; keine automatische Profiländerung oder Anlage eines zweiten Benutzers. |
| A16 | Ein neues SSO-Paket exportieren und ein älteres Paket mit Passwort- und Anwendungskennwortfeldern prüfen. | Gemäß vorgeschlagener Zugangsdatenregel enthält das neue Paket keine Zugangsdaten; aus dem alten Paket werden keine solchen Werte in Benutzerkonten übernommen. Die Behandlung älterer Formate wird ausdrücklich geprüft. |
| A17 | Eine Kennung mit Domain-Scope oder einen Aufruf mit `--usersuffix` prüfen. | Keine unbemerkte Änderung der SSO-Kennung. Nicht unterstützte Umwandlungen werden vor dem Schreiben abgelehnt. |
| A18 | Ein Benutzer der Quelle fehlt im Zielnetzwerk. | Das vereinbarte Verfahren zur Neuanlage oder vorherigen Bereitstellung greift; kein Ausweichen auf einen E-Mail-Treffer und keine Anlage eines SSO-Accounts. |
| A19 | Anmeldung und Zugriff auf die migrierte Website über die unterstützte SSO-Testintegration prüfen. | Die Person wird dem erwarteten WordPress-Benutzer zugeordnet und erhält die vorgesehene Website-Rolle. Abhängige globale Änderungen durch SSO-Hooks werden gesondert geprüft. |

Für die Vergleichstests werden Zugriffe anderer Prozesse kontrolliert. Zulässige Verwaltungsänderungen durch die Neuanlage werden explizit berücksichtigt; das Gesamtsystem kann wegen des neuen Site-Eintrags nicht vollständig unverändert bleiben.

## Umsetzungsstand nach Paket 3

Die ursprünglichen Befunde zu Single-Site-Überschreibung, öffentlichen Teilimporten, Login-/E-Mail-Zuordnung, Passwortübernahme, Hauptsite-Tabellenfilter und verschluckten Befehlsfehlern sind bearbeitet:

- Der Import prüft Multisite, Zieladresse einschließlich Site-Status, Paketgrundstruktur und Benutzeridentitäten vor der Site-Anlage. Die Zielprüfung wird unter einer MySQL-Sperre erneut ausgeführt. Das Zielnetzwerk ergibt sich aus dem geladenen WordPress-Kontext; bestehende Adressen werden netzwerkübergreifend abgelehnt.
- Tabellenimport, Benutzerimport und Referenzzuordnung sind intern an den im selben Lauf neu angelegten Site-Eintrag gebunden. Die bisherigen öffentlichen Änderungsbefehle sind deaktiviert beziehungsweise nicht mehr registriert.
- SSO-Konflikte führen vor der Site-Anlage zum Fehler. Neue lokale Konten verwenden Zufallspasswörter, vorhandene Konten behalten ihre globalen Daten. Die Benutzer-CSV enthält keine bekannten Credential- oder globalen Berechtigungsfelder; solche Felder aus Altpaketen werden verworfen. Beliebige benutzerdefinierte Benutzermetadaten werden nicht eingespielt.
- Hauptsite-Exporte enthalten standardmäßig nur die Core-Tabellen der Website. Explizite zusätzliche Tabellen müssen zur Quelle gehören; bekannte globale Tabellen und Tabellen anderer Websites sind ausgeschlossen. `--custom-tables` ergänzt die Standardauswahl.
- Externe Datenbank- und Ersetzungsbefehle laufen in Kindprozessen; ihr Fehlerstatus stoppt den Ablauf. Temporäre Arbeitsverzeichnisse liegen privat außerhalb des Webroots. Vorhandene Exportdateien werden nicht ersetzt. Neue, unvollständige Websites bleiben zur administrativen Prüfung erhalten.
- Das Umbenennen von SSO-Kennungen, die Codeübertragung von Plugins/Themes und das irreführende Versprechen einer atomaren SQL-Transaktion sind aus dem unterstützten Ablauf herausgenommen.

Die Regressionstests und ihr verifizierter Stand stehen in [Migrationstests](testing.md). Das ist keine vollständige Abnahme von Z1–Z7 und A1–A19: Die SQL-Prüfung ist noch keine Sicherheitsgrenze für beliebige Pakete; Paketformat, Integritätsprüfung und Ressourcengrenzen sind im folgenden Stand von Paket 4 ergänzt. Die unten beschriebene Vorprüfung ergänzt den Schutz vor Restressourcen. Offen bleiben Rennen mit fremden Site-Anlagen außerhalb der Migrationssperre, Prozessabbrüche, komplette Fehlerwiederherstellung, Erweiterungs-/Rollenkompatibilität und echte SSO-Anmeldungen. Die Produktionsfreigabe bleibt davon abhängig.

## Umsetzungsstand nach Paket 4

Vorprüfung und Migrationsplan sind für `import all` und `import all --dry-run` gemeinsam implementiert. `--format=json` liefert beim Dry-run eine maschinenlesbare Ausgabe. Der Plan nennt Quelle, Ziel, voraussichtliche Site-ID, Tabellenzuordnung, Benutzeraktionen, Medienumfang und den ermittelten Speicherbedarf. Ein Dry-run verändert weder Zieldatenbank noch Ziel-Uploads; das private Arbeitsverzeichnis wird anschließend entfernt. Wie bei anderen WP-CLI-Befehlen werden WordPress und aktive Plugins geladen; Seiteneffekte fremder Bootstrap-Hooks sind nicht isoliert.

Neue Exporte enthalten Formatversion 1, feste Pflichtdateien und ein Manifest mit Dateigrößen und SHA-256-Prüfsummen. Unversionierte oder unbekannte Formate werden vor Zieländerungen mit Hinweis auf einen erneuten Export abgelehnt. Die Archivprüfung umfasst Pfade, Kollisionen, Dateitypen, Verschlüsselung, Größen und Kompressionsverhältnisse. Prüfsummen sind keine Herkunftsbestätigung; SQL bleibt auf kontrollierte Exporte beschränkt.

Die Vorprüfung kontrolliert belegte Adressen, Benutzerzuordnung, explizite Datenbankrechte, Schreibrechte, verfügbaren lokalen Speicher und Restressourcen der voraussichtlich nächsten Site-ID. Alte Tabellen, Upload-Verzeichnisse und Mitgliedschaften blockieren den Import. Vor der Ausführung wird der Plan unter der Migrationssperre erneut aufgebaut. Ein früher Hook prüft die tatsächlich zugeteilte ID und Restressourcen vor der regulären WordPress-Initialisierung. Ein dann erkannter Konflikt kann bereits einen uninitialisierten Site-Eintrag hinterlassen; dessen Entfernung bleibt manuell.

Der Dry-run ist keine SQL-Probeausführung und reserviert weder Site-ID noch Speicher. Datenbankserver-Speicherplatz, fremde Hooks und konkurrierende Prozesse, zusätzliche Upload-Layouts, Rollengrants, Erweiterungen und reale SSO-Anmeldungen benötigen weitere Abnahme. Die Fehlerbehandlung nach bereits begonnenen Schreibschritten ist im folgenden Stand von Paket 5 ergänzt.

## Umsetzungsstand nach Paket 5

Ein echter Import benötigt ein dauerhaftes privates Laufverzeichnis. Dort werden vor der Paketprüfung eine unveränderte Kopie der Eingabe und anschließend atomar veröffentlichte Checkpoints gespeichert. Die Laufkennung, die tatsächlich angelegte Site-ID, Zielressourcen, Benutzer-ID-Zuordnungen und Ergebniszustände bleiben auch nach einem Abbruch prüfbar. Im Site-Metadatensatz kennzeichnet ausschließlich `rrze_migration_run` die Zugehörigkeit der neuen Website; fremde Websites erhalten keinen solchen Eintrag.

Die Ausführung ist in feste Schritte zerlegt. Eine installationsweite Datenbanksperre verhindert gleichzeitige rrze-cli-Importe auch auf unterschiedliche Ziele. Site-Zugehörigkeit und Sperre werden wiederholt geprüft. Vor der Erfolgsmeldung werden vorhandene beteiligte Benutzer, URLs, Tabellen, Rollen, Referenzen und enthaltene Mediendateien kontrolliert. Checkpoints enthalten keine Zugangsdaten oder rohen Fehlermeldungen.

`rrze-migration status` liest den Zustand und gibt Wiederherstellungshinweise aus. Ein offener Schritt nach einem Prozessabbruch bleibt ausdrücklich ungewiss; er wird nicht automatisch wiederholt. Die Wiederherstellung besteht aus manueller Prüfung und Löschung der unvollständigen neuen Website, erneuter Vorprüfung des aufbewahrten Pakets und einem frischen Import. Globale Konten bleiben erhalten und werden erneut anhand der SSO-Kennung geprüft. Das Verfahren überschreibt keine Netzwerk- oder Benutzersicherung über eine laufende Installation und ersetzt keine Sicherung vor der ursprünglichen manuellen Löschung.

Ein harter Abbruch kann Arbeitsdateien und noch laufende Kindprozesse zurücklassen. Deren Ende muss vor manuellen Bereinigungen feststehen. Details und Befehle stehen in [Migrationsläufe und Wiederherstellung](migration-recovery.md). Fremde parallele Schreibzugriffe, globale Nebenwirkungen von Erweiterungen und die Betriebsabnahme bleiben gesondert zu prüfen.

## Umsetzungsstand nach Paket 6

`rrze-migration wizard` führt interaktiv durch Export und Import. Die Auswahl der Quelle erfolgt über den vorhandenen WordPress-Kontext und `--url`; das Importziel wird ausdrücklich eingegeben. Die Importvorschau ist vorausgewählt. Eine Ausführung verlangt die Wiederholung der vollständigen Ziel-URL und eine ausdrückliche Zustimmung zum geprüften Plan. Der Export zeigt seine Quelle, Tabellen, Medienauswahl und Ausgabedatei vor der Bestätigung.

Der Wizard ruft die vorhandenen Export-/Importabläufe auf. Er kann weder eine Zielprüfung umgehen noch Websites löschen oder Schritte fortsetzen. Die Importfreigabe gilt für die aufbewahrte Paketkopie; die erneute Vorprüfung unter Sperre muss dieselben Entscheidungen ergeben. Änderungen an Site-ID, Zielressourcen oder Benutzeraktionen verlangen eine neue Prüfung und Bestätigung. Schwankender verfügbarer Speicher ist zulässig, sofern die Kapazitätsprüfung weiterhin besteht.

Nichtinteraktive Ein-/Ausgabe und automatische Bestätigung werden im Wizard abgelehnt. Die direkten Befehle bleiben für Skripte verfügbar. Ein Abbruch während der Bestätigung verändert keine Website; bereits vorbereitete private Laufdaten bleiben nachvollziehbar erhalten. Bedienung und Grenzen stehen unter [Export und Import mit dem Migrationsassistenten](migration-wizard.md).

## Noch offene Entscheidungen und Abschluss

ZIP-Export und Import verwenden gemeinsam `RRZE_MIGRATION_RUN_DIR` beziehungsweise `--run-dir` als private Ablage außerhalb der Webverzeichnisse. Jeder Export erhält einen eigenen Unterordner mit UTC-Zeitstempel, Site-ID und Zufallskennung; bestehende Dateien werden nicht überschrieben. Relative Importpfade beziehen sich auf diese Ablage. Eingaben aus WordPress oder `wp-content` werden auch bei indirekten Pfaden abgelehnt. Die vorhandenen Importlauf-Verzeichnisse und Statusabfragen bleiben kompatibel. Aufbewahrung, externer Transfer und die Prüfung weiterer Webserver-Freigaben liegen weiterhin bei der Administration.

Für nicht unterstützte Upload-Konfigurationen bietet der Import-Wizard inzwischen ausdrücklich die Fortsetzung ohne Uploads an; die Vorgabe bleibt Abbruch. Direkte Befehle benötigen `--skip-uploads`. Vorschau, Journal, Status und Abschluss unterscheiden den Datenimport von der ausstehenden Medienübertragung und -prüfung. Der Import schreibt dabei keine Paketmedien ins Ziel und nimmt keine gesonderte Umschreibung der Upload-Site-ID-Pfade vor. Die Administration muss den externen Transfer und die korrekten Pfade/URLs sicherstellen. Individuelle Upload-Verzeichnisse werden in diesem Modus nicht geprüft; bekannte Restressourcen im Standardpfad, Websites, Tabellen und Mitgliedschaften bleiben geschützt. Paketprüfung und private Entpackung bleiben vollständig aktiv. Dies ergänzt einen manuellen Arbeitsweg, keinen Adapter für beliebige Upload-Layouts.

Upload-Unterverzeichnisse können inzwischen ausdrücklich mit `--exclude-upload-dirs` oder im Export-Wizard vom Paket ausgeschlossen werden. Die Auswahl wird vor der Freigabe angezeigt, im Manifest gespeichert und beim Import sichtbar gemacht. Der Import-Wizard verlangt eine zusätzliche Zustimmung. Ausgeschlossene Dateien werden nicht übertragen oder auf Vollständigkeit geprüft; ihre Quelldateien bleiben erhalten. Ohne Auswahl bleibt der Export vollständig innerhalb des bisherigen Uploadumfangs und lehnt unzulässige Dateien weiterhin ab.

| Thema | Vorschlag für den ersten abgesicherten Umfang |
| --- | --- |
| Quellen | Single-Site sowie Hauptsite und Untersites einer Multisite. |
| Plugins und Themes | Vorab im Ziel bereitstellen; keine Installation oder Aktualisierung durch den Import. |
| Benutzerkonflikte | SSO-Kennung als Identität ist festgelegt. Vorgeschlagen: Abweichende Firmenadressen und Kollisionen stoppen die Vorprüfung bis zur fachlichen Klärung. |
| Fehlende WordPress-Benutzer | Festgelegt: Beim Import mit SSO-Kennung, Firmenadresse und zufälligem lokalem Passwort anlegen. Keine Anlage von Konten im SSO-System. |
| Zugangsdaten | Vorgeschlagen: keine Migration von Passwörtern, Anwendungskennwörtern, Sitzungstoken oder Rücksetzschlüsseln; Initialisierung neuer WordPress-Benutzer passend zur SSO-Integration festlegen. |
| SSO-Integration | Auf Production eingesetzte Version und Konfiguration, Kennungsnormalisierung, nötige Metadaten und eine geeignete Testanmeldung klären. Die Nutzung von `rrze-sso` auf Production ist festgelegt. |
| Eigene Tabellen und Erweiterungen | Konkrete benötigte Tabellen, Plugins und Themes inventarisieren; globale Tabellen zunächst ausschließen. |
| Medien außerhalb des Pakets | Gesonderten Transfer und dessen Vollständigkeitsprüfung verbindlich im Plan erfassen. |
| Betriebsumgebung | Unterstützte WordPress-, PHP- und Datenbankversionen sowie Domain-Mapping, Speichersysteme und gegebenenfalls mehrere Netzwerke erfassen. |

Arbeitspaket 1 ist abgeschlossen, sobald die noch offenen Entscheidungen getroffen, die Szenarienmatrix entsprechend aktualisiert und die erlaubten Änderungen samt Abnahmekriterien eindeutig sind. Dieses Dokument implementiert keine Schutzmaßnahmen und ersetzt die Tests und Betriebsabnahme der folgenden Arbeitspakete nicht.
