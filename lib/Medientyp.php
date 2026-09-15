<?php
/**
 * Was ist das wirklich für eine Datei?
 *
 * PHP legt den vom Browser gemeldeten Typ in `$_FILES[...]['type']` ab.
 * Dieser Wert stammt aus der Anfrage und lässt sich frei setzen – wer
 * „image/png" behauptet und etwas anderes schickt, kam damit durch jede
 * Prüfung, die nur diesen Wert ansieht.
 *
 * `finfo` sieht stattdessen in die Datei hinein. Fehlt die Erweiterung auf
 * einem sparsam ausgestatteten Hoster, greift eine kleine Erkennung über
 * die ersten Bytes – lieber eine knappe eigene Prüfung als gar keine.
 */
final class Medientyp
{
    public static function erkennen(string $pfad): string
    {
        if ($pfad === '' || !is_file($pfad)) {
            return '';
        }
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $typ = (string) @finfo_file($finfo, $pfad);
                finfo_close($finfo);
                if ($typ !== '') {
                    return self::vereinheitlichen($typ, $pfad);
                }
            }
        }
        return self::ausBytes($pfad);
    }

    /**
     * finfo meldet für manche Dateien etwas Allgemeineres, als hier
     * gebraucht wird: SVG ist für finfo oft schlicht `text/…`, und
     * MP4-Dateien mancher Kameras kommen als `application/octet-stream`.
     * Beides wird anhand der Bytes nachgeschärft.
     */
    private static function vereinheitlichen(string $typ, string $pfad): string
    {
        if (str_starts_with($typ, 'text/') || $typ === 'application/xml' || $typ === 'image/svg') {
            $kopf = (string) @file_get_contents($pfad, false, null, 0, 512);
            if (stripos($kopf, '<svg') !== false) {
                return 'image/svg+xml';
            }
        }
        if ($typ === 'application/octet-stream') {
            $eigen = self::ausBytes($pfad);
            if ($eigen !== '') {
                return $eigen;
            }
        }
        return $typ;
    }

    /** Erkennung über die Signatur am Dateianfang. */
    private static function ausBytes(string $pfad): string
    {
        $kopf = (string) @file_get_contents($pfad, false, null, 0, 32);
        if ($kopf === '') {
            return '';
        }
        if (str_starts_with($kopf, "\xFF\xD8\xFF"))                      { return 'image/jpeg'; }
        if (str_starts_with($kopf, "\x89PNG\r\n\x1a\n"))                 { return 'image/png'; }
        if (str_starts_with($kopf, 'GIF87a') || str_starts_with($kopf, 'GIF89a')) { return 'image/gif'; }
        if (str_starts_with($kopf, '%PDF-'))                             { return 'application/pdf'; }
        if (str_starts_with($kopf, "\x1a\x45\xDF\xA3"))                  { return 'video/webm'; }
        if (str_starts_with($kopf, 'RIFF') && substr($kopf, 8, 4) === 'WEBP') { return 'image/webp'; }
        if (substr($kopf, 4, 4) === 'ftyp') {
            $marke = substr($kopf, 8, 4);
            return $marke === 'qt  ' ? 'video/quicktime' : 'video/mp4';
        }
        if (stripos($kopf, '<svg') !== false || stripos($kopf, '<?xml') === 0) {
            $mehr = (string) @file_get_contents($pfad, false, null, 0, 512);
            if (stripos($mehr, '<svg') !== false) {
                return 'image/svg+xml';
            }
        }
        return '';
    }
}
