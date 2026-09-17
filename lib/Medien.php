<?php
/**
 * Medien – hochgeladene Dateien prüfen, ablegen, auflisten.
 *
 * Herausgezogen aus `app/medien.php`, weil inzwischen zwei Stellen Dateien
 * annehmen: die Mediathek selbst und der Bildwähler im Baukasten. Zwei
 * Kopien derselben Prüfungen laufen früher oder später auseinander – und
 * bei einer Prüfung, die entscheidet, was auf dem Server landet, ist das
 * die schlechteste Stelle dafür.
 *
 * Geprüft wird in dieser Reihenfolge, und jede Ablehnung bekommt einen
 * Grund im Klartext:
 *
 *   1. Der Fehlercode des Uploads (zu groß, halb angekommen, …)
 *   2. Der Typ **aus dem Inhalt**, nicht aus der Angabe des Browsers.
 *      `$_FILES[...]['type']` kommt aus der Anfrage und lässt sich frei
 *      setzen.
 *   3. Bei Bildern: Lässt es sich überhaupt als Bild öffnen? Eine Datei,
 *      die nur wie ein PNG anfängt, fällt hier durch.
 *   4. Die Größe.
 *
 * Der gespeicherte Name entsteht neu aus einem Slug plus Zufall; der Name
 * aus dem Browser wird nie zum Dateinamen. Die Endung stammt aus der
 * Weißliste, und `uploads/.htaccess` verbietet dort die Ausführung.
 */
final class Medien
{
    /** mime => Endung. Was hier nicht steht, kommt nicht auf den Server. */
    public const ERLAUBT = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'image/svg+xml' => 'svg', 'image/gif' => 'gif', 'application/pdf' => 'pdf',
    ];

    public const GRENZE = 12 * 1024 * 1024;

    /**
     * Nimmt eine hochgeladene Datei an.
     *
     * @param array<string,mixed> $datei ein Eintrag aus $_FILES
     * @return array{0:string,1:string} [Pfad ab Wurzel, Grund wenn nicht]
     */
    public static function uebernehmen(array $datei): array
    {
        $wie    = (string) ($datei['name'] ?? '');
        $tmp    = (string) ($datei['tmp_name'] ?? '');
        $code   = (int) ($datei['error'] ?? UPLOAD_ERR_NO_FILE);
        $groesse = (int) ($datei['size'] ?? 0);

        if ($code !== UPLOAD_ERR_OK) {
            return ['', self::fehlerText($code)];
        }
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['', 'Die Datei ist nicht als Upload angekommen.'];
        }

        $typ = Medientyp::erkennen($tmp);
        if (!isset(self::ERLAUBT[$typ])) {
            return ['', 'Dateityp nicht erlaubt (' . ($typ !== '' ? $typ : 'unbekannt') . ')'];
        }
        if (str_starts_with($typ, 'image/') && $typ !== 'image/svg+xml' && @getimagesize($tmp) === false) {
            return ['', 'sieht aus wie ein Bild, lässt sich aber nicht öffnen'];
        }
        if ($groesse > self::GRENZE) {
            return ['', 'über ' . Util::bytes(self::GRENZE)];
        }

        $ordner = GP_ROOT . '/uploads/w' . Tenant::id() . '/medien';
        if (!is_dir($ordner) && !@mkdir($ordner, 0750, true) && !is_dir($ordner)) {
            return ['', 'der Ordner uploads/ ließ sich nicht anlegen'];
        }

        $name = Util::slug(pathinfo($wie, PATHINFO_FILENAME), 40)
              . '-' . substr(Util::token(3), 0, 5) . '.' . self::ERLAUBT[$typ];
        if (!move_uploaded_file($tmp, $ordner . '/' . $name)) {
            return ['', 'konnte nicht gespeichert werden. Ist uploads/ beschreibbar?'];
        }

        $pfad  = 'uploads/w' . Tenant::id() . '/medien/' . $name;
        $masse = @getimagesize($ordner . '/' . $name) ?: [0, 0];
        Tenant::insert('media', [
            'dateiname' => $wie, 'pfad' => $pfad, 'mime' => $typ,
            'groesse' => $groesse,
            'breite' => (int) $masse[0], 'hoehe' => (int) $masse[1],
            'user_id' => Auth::id(),
        ]);
        return [$pfad, ''];
    }

    /**
     * Mehrere auf einmal – so kommen sie aus einem Feld mit `multiple`.
     *
     * @return array{0:array<int,string>,1:array<int,string>} [Pfade, Absagen]
     */
    public static function uebernehmenViele(array $dateien): array
    {
        $pfade  = [];
        $absagen = [];
        foreach (array_keys((array) ($dateien['name'] ?? [])) as $i) {
            [$pfad, $grund] = self::uebernehmen([
                'name'     => (string) $dateien['name'][$i],
                'tmp_name' => (string) $dateien['tmp_name'][$i],
                'error'    => (int) $dateien['error'][$i],
                'size'     => (int) $dateien['size'][$i],
            ]);
            if ($pfad !== '') {
                $pfade[] = $pfad;
            } else {
                $absagen[] = (string) $dateien['name'][$i] . ' – ' . $grund;
            }
        }
        return [$pfade, $absagen];
    }

    /**
     * Jede Absage bekommt einen Grund. Vorher wurden sie stillschweigend
     * übersprungen, und am Ende stand nur „Nichts hochgeladen" – womit
     * niemand etwas anfangen kann.
     */
    public static function fehlerText(int $code): string
    {
        $grenze = App::uploadGrenze();
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                'zu groß für diesen Server'
                . ($grenze > 0 ? ' (Grenze: ' . Util::bytes($grenze) . ')' : ''),
            UPLOAD_ERR_PARTIAL   => 'nur halb angekommen, bitte noch einmal',
            UPLOAD_ERR_NO_FILE   => 'keine Datei ausgewählt',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                'der Server konnte nicht schreiben – das muss der Hoster beheben',
            UPLOAD_ERR_EXTENSION => 'vom Server abgelehnt',
            default              => 'unbekannter Fehler ' . $code,
        };
    }

    /**
     * Nur die Bilder, für den Wähler im Baukasten.
     *
     * PDF gehört in die Mediathek, aber nicht in ein Bildfeld: Ein PDF als
     * Aufmacherbild wäre ein kaputtes Bild auf der Website.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function bilder(int $limit = 200): array
    {
        return Tenant::all('media', "mime LIKE 'image/%'", [], 'id DESC', $limit);
    }
}
