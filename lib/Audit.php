<?php
/**
 * Audit – das Protokoll.
 *
 * Wer hat wann was geändert. Ohne dieses Protokoll ist die Frage „warum steht
 * hier jetzt ein anderer Preis?“ in einer Akademie mit sechs Trainern nicht
 * beantwortbar. Es wird nur geschrieben, nie geändert.
 */
final class Audit
{
    public static function schreiben(string $aktion, string $objekt = '', int $objektId = 0, string $beschreibung = ''): void
    {
        try {
            DB::insert('audit_log', [
                'workspace_id' => Tenant::id(),
                'user_id'      => Auth::id(),
                'aktion'       => $aktion,
                'objekt'       => $objekt,
                'objekt_id'    => $objektId,
                'beschreibung' => $beschreibung,
                'ip'           => Util::ip(),
                /* Im Support Mode steht hier, welcher Betreiber es war. Die
                   Zeile gehört dann nicht dem Inhaber, auch wenn sie unter
                   seinem Zugang entstand. */
                'betreiber_id' => Support::betreiberId(),
                'erstellt'     => Util::jetzt(),
            ]);
        } catch (Throwable $e) {
            // Ein fehlgeschlagenes Protokoll darf nie die Handlung verhindern.
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function letzte(int $limit = 50, string $objekt = '', int $objektId = 0): array
    {
        $wo = '';
        $p  = [];
        if ($objekt !== '') {
            $wo = 'objekt = :o';
            $p['o'] = $objekt;
            if ($objektId > 0) {
                $wo .= ' AND objekt_id = :oid';
                $p['oid'] = $objektId;
            }
        }
        return Tenant::all('audit_log', $wo, $p, 'id DESC', $limit);
    }

    private const TEXTE = [
        'login'            => 'Anmeldung',
        'logout'           => 'Abmeldung',
        'erstellt'         => 'angelegt',
        'geaendert'        => 'geändert',
        'geloescht'        => 'gelöscht',
        'veroeffentlicht'  => 'veröffentlicht',
        'versendet'        => 'versendet',
        'storniert'        => 'storniert',
        'bezahlt'          => 'als bezahlt verbucht',
        'ki_angenommen'    => 'KI-Vorschlag übernommen',
        'ki_abgelehnt'     => 'KI-Vorschlag verworfen',
        'export'           => 'Daten exportiert',
        'support_beginn'   => 'Support-Zugriff begonnen',
        'support_ende'     => 'Support-Zugriff beendet',
    ];

    public static function text(string $aktion): string
    {
        return self::TEXTE[$aktion] ?? $aktion;
    }
}
