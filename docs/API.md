# Schnittstelle: Empfänger abholen

Diese Beschreibung ist zum Weitergeben gedacht – an den, der das
Newslettersystem (Acumen Mail) mit GolfPro CMS verbindet.

In einem Satz: **GolfPro CMS liefert die Empfänger als JSON, das
Newslettersystem holt sie ab.** Es wird nichts von hier aus verschickt.

---

## Warum abholen und nicht schieben

GolfPro CMS weiß nicht, wann das Newslettersystem erreichbar ist, ob es
gerade umzieht oder ob sein Zugang noch gilt. Ein Abruf liegt dagegen
vollständig in der Hand dessen, der die Daten braucht: Er holt, so oft er
mag, wiederholt bei einem Fehler und bestimmt selbst, wann ein Abgleich als
erledigt gilt. Ein Versand von hier aus bräuchte eine Warteschlange,
Wiederholungen und eine Überwachung – für dieselbe Wirkung.

---

## Zugang

Der Inhaber erzeugt den Schlüssel unter **Einstellungen → Schnittstelle**.
Er steht dort genau einmal im Klartext; gespeichert wird nur sein Abdruck.
Geht er verloren, wird ein neuer erzeugt – der alte gilt dann nicht mehr.

Jede Anfrage trägt ihn in einer Kopfzeile:

```
Authorization: Bearer gp_2_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6
```

Wer keine eigenen Kopfzeilen setzen kann, hängt `&schluessel=…` an die
Adresse. Das funktioniert, steht aber in jedem Server-, Proxy- und
Browserprotokoll. Die Kopfzeile ist der bessere Weg.

Der Schlüssel gilt für **einen** Workspace – seine Nummer steht vorn drin.
Mehr als diesen einen bekommt er nie zu sehen.

---

## Adressen

| Zweck | Adresse |
|---|---|
| Selbsttest | `GET /api.php?was=auskunft` |
| Empfänger | `GET /api.php?was=empfaenger` |
| Abmeldungen | `GET /api.php?was=abmeldungen` |

Läuft Apache mit `mod_rewrite`, geht auch der saubere Weg:
`/api/v1/auskunft`, `/api/v1/empfaenger`, `/api/v1/abmeldungen`. Beides
führt zum selben Ziel; die Fassung mit `api.php` läuft überall.

Nur `GET` (und `HEAD`). Die Schnittstelle liest, sie schreibt nichts.

### Parameter

| Parameter | Vorgabe | Bedeutung |
|---|---|---|
| `seite` | `1` | Welche Seite. |
| `pro_seite` | `200` | Wie viele je Seite, höchstens `500`. |
| `seit` | – | Nur, was sich seitdem geändert hat. `2026-09-01`, `2026-09-01 10:00:00` oder `2026-09-01T10:00:00Z`. |

---

## Der erste Griff: Selbsttest

```
curl -H "Authorization: Bearer gp_2_…" \
     "https://newsletter-consulting.de/procms/api.php?was=auskunft"
```

```json
{
  "workspace": "Golf Academy Bergmann",
  "version": "v1",
  "zeit": "2026-09-17 08:12:39",
  "zeitzone": "Europe/Berlin",
  "empfaenger": 30,
  "abmeldungen": 10,
  "adressen": {
    "empfaenger": "https://…/api.php?was=empfaenger",
    "abmeldungen": "https://…/api.php?was=abmeldungen",
    "auskunft": "https://…/api.php?was=auskunft"
  }
}
```

Die erste Frage beim Einrichten ist immer dieselbe – „komme ich überhaupt
rein?" –, und sie lässt sich so beantworten, ohne dass ein einziger
Kundendatensatz über die Leitung geht.

---

## Empfänger

```
GET /api.php?was=empfaenger&pro_seite=200
```

Geliefert wird, wer die Newsletter-Einwilligung gesetzt hat, eine
E-Mail-Adresse hat und den Status `aktiv` trägt. Genau dieselbe Auswahl
bekommt auch eine Kampagne aus GolfPro CMS selbst.

```json
{
  "stand": "2026-09-17 08:12:43",
  "gesamt": 30,
  "seite": 1,
  "seiten": 1,
  "pro_seite": 200,
  "weiter": null,
  "empfaenger": [
    {
      "id": 31,
      "email": "anja.brandt@beispiel.de",
      "vorname": "Anja",
      "nachname": "Brandt",
      "name": "Anja Brandt",
      "sprache": "de",
      "newsletter": true,
      "status": "aktiv",
      "ort": "Karlsruhe",
      "land": "DE",
      "hcp": "10.8",
      "heimclub": "GC Birkenau",
      "tags": ["Turnierspieler", "Stammkunde"],
      "kunde_seit": "2024-03-02",
      "geaendert": "2024-03-02 18:29:08",
      "abmelde_url": "https://…/abmelden-newsletter.php?t=c080f3ec…",
      "einwilligung": {
        "erteilt": true,
        "am": "2024-03-02 18:29:08",
        "quelle": "Formular: Platzreife-Anfrage"
      }
    }
  ]
}
```

`id` ist die Kundennummer in GolfPro CMS. Sie ist der stabile Bezug: Eine
E-Mail-Adresse kann sich ändern, die Nummer nicht.

`abmelde_url` gehört in jede Aussendung – auch in die aus dem anderen
System. Sie zeigt hierher, damit eine Abmeldung in **beiden** Datenbanken
gilt und nicht nur in einer.

### Was nicht geliefert wird

Straße, Telefonnummer, Notizen, Umsätze, Termine, Trainingspläne. Ein
Newsletter braucht das nicht, und wer Daten weitergibt, gibt so wenige
weiter wie möglich.

---

## Abmeldungen

```
GET /api.php?was=abmeldungen
```

Dieselbe Struktur, aber die andere Hälfte: alle mit E-Mail-Adresse, die
**nicht (mehr)** einwilligen – Haken entfernt, oder Status `inaktiv` oder
`archiviert`.

**Diese Liste ist nicht optional.** Ohne sie verschwindet ein Abgemeldeter
einfach aus der Empfängerliste, und das Newslettersystem kann nicht
unterscheiden, ob jemand widerrufen hat oder ob die Seite nur zu Ende war.
Eine Abmeldung, die nicht ankommt, ist eine Abmahnung.

Empfehlung: bei jedem Abgleich beide Listen holen, die einen anlegen oder
aktualisieren, die anderen austragen.

---

## Nur das Neue holen

Jede Antwort nennt oben ihren `stand` – die Serverzeit zu Beginn der
Antwort. Wer den aufbewahrt und beim nächsten Mal als `seit` mitschickt,
bekommt nur, was sich seitdem geändert hat:

```
GET /api.php?was=empfaenger&seit=2026-09-17T08:12:43Z
```

Sortiert wird nach Änderungszeitpunkt, nicht nach Namen. Das ist Absicht:
Wer Seite für Seite abholt, während nebenher jemand einen Kunden anlegt,
bekäme bei einer Sortierung nach Namen Datensätze doppelt oder gar nicht.

Der Zeitpunkt ist die Serverzeit in `Europe/Berlin`. `stand` unverändert
zurückzuschicken ist daher immer richtig; eine selbst gebildete Zeit sollte
die Zeitzone mitbringen.

---

## Seiten

Solange `weiter` nicht `null` ist, gibt es mehr:

```python
url = "https://…/api.php?was=empfaenger&seit=" + letzter_stand
while url:
    antwort = hole(url, kopf={"Authorization": "Bearer " + schluessel})
    for e in antwort["empfaenger"]:
        uebernehmen(e)
    url = antwort["weiter"]
letzter_stand = antwort["stand"]
```

`weiter` ist eine vollständige Adresse samt aller Parameter – sie lässt sich
unverändert weiterverwenden.

---

## Fehler

Alle Fehler kommen als JSON, mit passendem HTTP-Status:

```json
{"fehler": {"code": "schluessel_ungueltig", "text": "Dieser Schlüssel stimmt nicht."}}
```

| Status | Code | Was tun |
|---|---|---|
| 401 | `kein_schluessel` | Kopfzeile `Authorization` fehlt oder wird vom Server verschluckt (siehe unten). |
| 401 | `schluessel_ungueltig` | Schlüssel falsch oder widerrufen. Neuen erzeugen lassen. |
| 429 | `zu_viele_versuche` | Zu viele Fehlversuche. 15 Minuten warten – `Retry-After` sagt es genau. |
| 400 | `seit_unlesbar` | `seit` war kein Zeitpunkt. |
| 404 | `unbekannt` | `was` kennt die Schnittstelle nicht. |
| 405 | `verfahren_nicht_erlaubt` | Es war kein `GET`. |

### Wenn immer „kein_schluessel" kommt

Fast immer verschluckt der Server die Kopfzeile `Authorization`, bevor PHP
sie sieht – das tun CGI und FastCGI von sich aus. Die mitgelieferte
`.htaccess` reicht sie durch:

```apache
RewriteCond %{HTTP:Authorization} .
RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

Läuft nginx oder greift die `.htaccess` nicht, hilft übergangsweise
`&schluessel=…` in der Adresse.

---

## Was die Schnittstelle nicht kann

* **Schreiben.** Sie liest. Ein Empfänger, der sich im Newslettersystem
  abmeldet, wird hier nicht automatisch abgemeldet – dafür ist die
  `abmelde_url` da, die hierher zeigt.
* **Mehrere Workspaces.** Ein Schlüssel, ein Workspace.
* **Auf Ereignisse hinweisen.** Es gibt keinen Rückruf bei einer neuen
  Anmeldung. Ein Abgleich im Stundentakt ist genug und kostet nichts.
* **Aus Vorführdaten liefern.** Ein Workspace mit Demo-Daten antwortet
  ganz normal – die Adressen darin sind aber erfunden.
