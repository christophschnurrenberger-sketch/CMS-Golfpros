<?php
/**
 * Icon – ein einheitliches Strichsymbol-Set als Inline-SVG.
 *
 * Inline statt Icon-Font oder Sprite-Datei: keine zusätzliche Anfrage, keine
 * Blitzsekunde ohne Symbol beim Laden, und die Farbe folgt `currentColor`.
 * Alle Symbole sitzen im 24er-Raster mit 1,75 Strichstärke – gemischte
 * Strichstärken sind das Erste, was eine Oberfläche billig aussehen lässt.
 */
final class Icon
{
    private const PFADE = [
        /* Navigation */
        'dashboard'   => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'website'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M7 6.5h.01M10 6.5h.01"/>',
        'customers'   => '<path d="M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20"/><circle cx="9" cy="7" r="3.2"/><path d="M22 20v-1.5a4 4 0 0 0-3-3.87"/><path d="M16.5 4.2a4 4 0 0 1 0 5.6"/>',
        'leads'       => '<path d="M3 4h18l-7 8.5V20l-4 1.5V12.5z"/>',
        'calendar'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
        'bookings'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/><path d="M9 15.5l2 2 4-4"/>',
        'training'    => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3.5h6v3H9z"/><path d="M9 11h6M9 15h4"/>',
        'video'       => '<rect x="2.5" y="5.5" width="13" height="13" rx="2.5"/><path d="M15.5 10l6-3.2v10.4l-6-3.2z"/>',
        'courses'     => '<path d="M12 3.5 22 8l-10 4.5L2 8z"/><path d="M6 10.2V16c0 1.7 2.7 3 6 3s6-1.3 6-3v-5.8"/>',
        'products'    => '<path d="M4 8h16l-1.2 12.2a1 1 0 0 1-1 .8H6.2a1 1 0 0 1-1-.8z"/><path d="M8.5 8V6a3.5 3.5 0 0 1 7 0v2"/>',
        'payments'    => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19"/><path d="M6.5 15h3"/>',
        'invoices'    => '<path d="M6 2.5h8.5L19 7v13.5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1v-17a1 1 0 0 1 1-1z"/><path d="M14 2.5V7h5"/><path d="M8.5 12h7M8.5 16h4"/>',
        'marketing'   => '<path d="M3.5 9.5v4a1 1 0 0 0 1 1h2l6 4.5V4L6.5 8.5h-2a1 1 0 0 0-1 1z"/><path d="M17 8.5a5 5 0 0 1 0 7"/><path d="M19.5 6a8.5 8.5 0 0 1 0 12"/>',
        'newsletter'  => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="m3.5 7 8.5 6 8.5-6"/>',
        'automations' => '<path d="M13 2.5 4 13.5h6l-1 8 9-11h-6z"/>',
        'content'     => '<path d="M17.5 3.5a2.6 2.6 0 0 1 3.7 3.7L8 20.4l-5 1.3 1.3-5z"/><path d="m15.5 5.5 3 3"/>',
        'events'      => '<path d="M5 21V3.5"/><path d="M5 4.2h11l-1.8 3.4L16 11H5z"/>',
        'community'   => '<path d="M20.5 12a8.5 8.5 0 0 1-12.3 7.6L3 21l1.5-5A8.5 8.5 0 1 1 20.5 12z"/>',
        'analytics'   => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'ai'          => '<path d="m12 2.8 1.9 5.3 5.3 1.9-5.3 1.9L12 17.2l-1.9-5.3L4.8 10l5.3-1.9z"/><path d="m18.5 16.5.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/>',
        'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-1.8-.3 1.6 1.6 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0 .3-1.8 1.6 1.6 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 1.8.3H9a1.6 1.6 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0-.3 1.8V9a1.6 1.6 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/>',

        /* Werkzeuge und Zustände */
        'search'      => '<circle cx="11" cy="11" r="7"/><path d="m20.5 20.5-4.3-4.3"/>',
        'bell'        => '<path d="M18 8.5a6 6 0 1 0-12 0c0 6-2.5 7.5-2.5 7.5h17S18 14.5 18 8.5z"/><path d="M13.7 20a2 2 0 0 1-3.4 0"/>',
        'plus'        => '<path d="M12 5v14M5 12h14"/>',
        'minus'       => '<path d="M5 12h14"/>',
        'check'       => '<path d="m4.5 12.5 5 5 10-11"/>',
        'x'           => '<path d="M18 6 6 18M6 6l12 12"/>',
        'menu'        => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'more'        => '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
        'chevron-down'  => '<path d="m5.5 8.5 6.5 7 6.5-7"/>',
        'chevron-up'    => '<path d="m5.5 15.5 6.5-7 6.5 7"/>',
        'chevron-right' => '<path d="m9 4.5 7.5 7.5L9 19.5"/>',
        'chevron-left'  => '<path d="M15 4.5 7.5 12 15 19.5"/>',
        'arrow-right' => '<path d="M4 12h15.5"/><path d="m13.5 5.5 6.5 6.5-6.5 6.5"/>',
        'arrow-left'  => '<path d="M20 12H4.5"/><path d="m10.5 5.5-6.5 6.5 6.5 6.5"/>',
        'arrow-up'    => '<path d="M12 20V4.5"/><path d="m5.5 10.5 6.5-6.5 6.5 6.5"/>',
        'arrow-down'  => '<path d="M12 4v15.5"/><path d="m5.5 13.5 6.5 6.5 6.5-6.5"/>',
        'edit'        => '<path d="M12 20h8"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z"/>',
        'trash'       => '<path d="M3.5 6h17"/><path d="M8.5 6V4.5a1 1 0 0 1 1-1h5a1 1 0 0 1 1 1V6"/><path d="M6 6v14a1.5 1.5 0 0 0 1.5 1.5h9A1.5 1.5 0 0 0 18 20V6"/><path d="M10 10.5v6M14 10.5v6"/>',
        'copy'        => '<rect x="8.5" y="8.5" width="12" height="12" rx="2"/><path d="M4.5 15.5A2 2 0 0 1 3.5 14V5a2 2 0 0 1 2-2h9a2 2 0 0 1 1.5.7"/>',
        'download'    => '<path d="M12 3.5v11"/><path d="m7 10 5 4.5 5-4.5"/><path d="M4 17.5v1.5a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-1.5"/>',
        'upload'      => '<path d="M12 15.5v-11"/><path d="m7 9 5-4.5L17 9"/><path d="M4 17.5v1.5a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-1.5"/>',
        'external'    => '<path d="M14 4h6v6"/><path d="M20 4 10.5 13.5"/><path d="M18 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h5"/>',
        'filter'      => '<path d="M3.5 5.5h17l-6.5 7.5V19l-4 2v-8z"/>',
        'clock'       => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'pin'         => '<path d="M12 21s7-5.6 7-11a7 7 0 1 0-14 0c0 5.4 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/>',
        'phone'       => '<path d="M6.5 3.5h3l1.5 4-2 1.4a12 12 0 0 0 6.1 6.1l1.4-2 4 1.5v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.5 5.7a2 2 0 0 1 2-2.2z"/>',
        'mail'        => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="m3.5 7 8.5 6 8.5-6"/>',
        'user'        => '<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/>',
        'user-plus'   => '<circle cx="9.5" cy="8" r="3.6"/><path d="M2.5 20.5a7 7 0 0 1 12-4.9"/><path d="M18 13v6M15 16h6"/>',
        'star'        => '<path d="m12 3.5 2.7 5.6 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1L3.2 10l6.1-.9z"/>',
        'play'        => '<path d="M7 4.5 19 12 7 19.5z"/>',
        'pause'       => '<rect x="7" y="4.5" width="3.5" height="15" rx="1"/><rect x="13.5" y="4.5" width="3.5" height="15" rx="1"/>',
        'eye'         => '<path d="M2 12s3.8-6.5 10-6.5S22 12 22 12s-3.8 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.8"/>',
        'lock'        => '<rect x="4.5" y="10" width="15" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'shield'      => '<path d="M12 2.5 20 6v6c0 5-3.4 8.3-8 9.5-4.6-1.2-8-4.5-8-9.5V6z"/><path d="m9 12 2 2 4-4"/>',
        'alert'       => '<path d="M12 3.5 22 20H2z"/><path d="M12 9.5v4.5M12 17.2h.01"/>',
        'info'        => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8h.01"/>',
        'help'        => '<circle cx="12" cy="12" r="8.5"/><path d="M9.5 9.5a2.6 2.6 0 0 1 5 .8c0 1.7-2.5 2.2-2.5 3.7"/><path d="M12 17.2h.01"/>',
        'link'        => '<path d="M10 13.5a4 4 0 0 0 5.7 0l3-3a4 4 0 1 0-5.7-5.7L11.5 6.3"/><path d="M14 10.5a4 4 0 0 0-5.7 0l-3 3a4 4 0 1 0 5.7 5.7l1.4-1.4"/>',
        'image'       => '<rect x="3" y="4.5" width="18" height="15" rx="2.5"/><circle cx="8.5" cy="10" r="1.8"/><path d="m4 17 5-5 4.5 4.5L16 14l4 4"/>',
        'folder'      => '<path d="M3.5 7a2 2 0 0 1 2-2h3.3l2 2.5h7.7a2 2 0 0 1 2 2v8.5a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/>',
        'list'        => '<path d="M8.5 6.5h12M8.5 12h12M8.5 17.5h12"/><path d="M4 6.5h.01M4 12h.01M4 17.5h.01"/>',
        'grid'        => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
        'layers'      => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
        'trend-up'    => '<path d="M3 17 10 10l4 4 7-7"/><path d="M15 7h6v6"/>',
        'trend-down'  => '<path d="M3 7l7 7 4-4 7 7"/><path d="M15 17h6v-6"/>',
        'euro'        => '<path d="M17.5 6.3A7 7 0 0 0 7 12a7 7 0 0 0 10.5 5.7"/><path d="M4.5 10h9M4.5 14h9"/>',
        'percent'     => '<path d="m19 5-14 14"/><circle cx="7.5" cy="7.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>',
        'target'      => '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1"/>',
        'award'       => '<circle cx="12" cy="9" r="5.5"/><path d="m8.5 13.5-1 7.5 4.5-2.4 4.5 2.4-1-7.5"/>',
        'flame'       => '<path d="M12 2.5s5.5 4.2 5.5 9.3a5.5 5.5 0 0 1-11 0c0-2 1-3.6 2-4.6 0 1.6.8 2.6 1.8 2.6 1.2 0 1.7-1.4 1.7-3.2 0-1.6 0-3 0-4.1z"/>',
        'zap'         => '<path d="M13 2.5 4 13.5h6l-1 8 9-11h-6z"/>',
        'sun'         => '<circle cx="12" cy="12" r="4.2"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M19.1 4.9l-1.8 1.8M6.7 17.3l-1.8 1.8"/>',
        'moon'        => '<path d="M20.5 14.3A8.5 8.5 0 0 1 9.7 3.5a8.5 8.5 0 1 0 10.8 10.8z"/>',
        'command'     => '<path d="M7.5 4.5a3 3 0 1 1-3 3h12a3 3 0 1 1-3-3v12a3 3 0 1 1 3-3h-12a3 3 0 1 1 3 3z"/>',
        'logout'      => '<path d="M9.5 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3.5"/><path d="M15.5 16.5 20 12l-4.5-4.5"/><path d="M20 12H9"/>',
        'refresh'     => '<path d="M20.5 12a8.5 8.5 0 1 1-2.5-6"/><path d="M20.5 3.5V9H15"/>',
        'save'        => '<path d="M5 3.5h11L20.5 8v11.5a1.5 1.5 0 0 1-1.5 1.5H5a1.5 1.5 0 0 1-1.5-1.5v-14A1.5 1.5 0 0 1 5 3.5z"/><path d="M8 3.5v5h7v-5M8 21v-6h8v6"/>',
        'undo'        => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
        'redo'        => '<path d="m15 14 5-5-5-5"/><path d="M20 9H9.5a5.5 5.5 0 0 0 0 11H13"/>',
        'grip'        => '<circle cx="9" cy="6" r="1.3"/><circle cx="15" cy="6" r="1.3"/><circle cx="9" cy="12" r="1.3"/><circle cx="15" cy="12" r="1.3"/><circle cx="9" cy="18" r="1.3"/><circle cx="15" cy="18" r="1.3"/>',
        'palette'     => '<path d="M12 21a9 9 0 1 1 9-9c0 2.2-2 2.6-3.5 2.6H16a2 2 0 0 0-1.4 3.4A2 2 0 0 1 12 21z"/><circle cx="7.5" cy="11" r="1.2"/><circle cx="10" cy="7" r="1.2"/><circle cx="15" cy="7.5" r="1.2"/>',
        'type'        => '<path d="M4 6.5V4.5h16v2"/><path d="M12 4.5v15M8.5 19.5h7"/>',
        'phone-device'=> '<rect x="6.5" y="2.5" width="11" height="19" rx="2.5"/><path d="M11 18.8h2"/>',
        'tablet'      => '<rect x="4.5" y="2.5" width="15" height="19" rx="2.5"/><path d="M11 18.8h2"/>',
        'monitor'     => '<rect x="2.5" y="4" width="19" height="12.5" rx="2"/><path d="M8.5 21h7M12 16.5V21"/>',
        'code'        => '<path d="m8 7.5-5 4.5 5 4.5M16 7.5l5 4.5-5 4.5M14 4l-4 16"/>',
        'gift'        => '<rect x="3" y="8.5" width="18" height="4" rx="1"/><path d="M5 12.5V20a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-7.5"/><path d="M12 8.5V21"/><path d="M12 8.5S10.5 3 8 3a2.5 2.5 0 0 0 0 5.5zM12 8.5S13.5 3 16 3a2.5 2.5 0 0 1 0 5.5z"/>',
        'ticket'      => '<path d="M3.5 8.5V6a1.5 1.5 0 0 1 1.5-1.5h14A1.5 1.5 0 0 1 20.5 6v2.5a3.5 3.5 0 0 0 0 7V18a1.5 1.5 0 0 1-1.5 1.5H5A1.5 1.5 0 0 1 3.5 18v-2.5a3.5 3.5 0 0 0 0-7z"/><path d="M14 5v14"/>',
        'book'        => '<path d="M3.5 4.5A2 2 0 0 1 5.5 3H11v17H5.5a2 2 0 0 0-2 1.5z"/><path d="M20.5 4.5A2 2 0 0 0 18.5 3H13v17h5.5a2 2 0 0 1 2 1.5z"/>',
        'send'        => '<path d="M21.5 2.5 2.5 10l7.5 3 3 7.5z"/><path d="m10 13 11.5-10.5"/>',
        'chart-pie'   => '<path d="M12 3.5v8.5h8.5A8.5 8.5 0 0 0 12 3.5z"/><path d="M20 15.5A8.5 8.5 0 1 1 10 3.7"/>',
        'activity'    => '<path d="M2.5 12h4l3-8 5 16 3-8h4"/>',
        'message'     => '<path d="M20.5 11.5a7.5 7.5 0 0 1-10.8 6.7L4 20l1.8-5.2A7.5 7.5 0 1 1 20.5 11.5z"/>',
        'heart'       => '<path d="M12 20.5S3.5 15.2 3.5 9.2A4.7 4.7 0 0 1 12 6.5a4.7 4.7 0 0 1 8.5 2.7c0 6-8.5 11.3-8.5 11.3z"/>',
        'trophy'      => '<path d="M7 4.5h10v5a5 5 0 0 1-10 0z"/><path d="M7 6H4.5a2.5 2.5 0 0 0 2.5 4M17 6h2.5a2.5 2.5 0 0 1-2.5 4"/><path d="M12 14.5V18M8.5 21h7"/>',
        'building'    => '<rect x="4.5" y="3" width="15" height="18" rx="1.5"/><path d="M9 7h.01M15 7h.01M9 11h.01M15 11h.01M9 15h.01M15 15h.01"/><path d="M10.5 21v-3h3v3"/>',
        'globe'       => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17"/><path d="M12 3.5a13 13 0 0 1 0 17 13 13 0 0 1 0-17z"/>',
        'sliders'     => '<path d="M4 8h8M16 8h4M4 16h4M12 16h8"/><circle cx="14" cy="8" r="2"/><circle cx="10" cy="16" r="2"/>',
        'dumbbell'    => '<path d="M6.5 6.5v11M3.5 9v6M17.5 6.5v11M20.5 9v6M6.5 12h11"/>',
        'flag'        => '<path d="M5 21V4"/><path d="M5 4.5h13l-2 4 2 4H5z"/>',
        'qr'          => '<rect x="3.5" y="3.5" width="6" height="6" rx="1"/><rect x="14.5" y="3.5" width="6" height="6" rx="1"/><rect x="3.5" y="14.5" width="6" height="6" rx="1"/><path d="M14.5 14.5h2.5v2.5h-2.5zM20.5 14.5v2M18 20.5h2.5V18"/>',
        'repeat'      => '<path d="M17 2.5 20.5 6 17 9.5"/><path d="M3.5 11V9a3 3 0 0 1 3-3h14"/><path d="M7 21.5 3.5 18 7 14.5"/><path d="M20.5 13v2a3 3 0 0 1-3 3h-14"/>',
        'split'       => '<path d="M12 20.5V12"/><path d="M12 12 5 5M12 12l7-7"/><path d="M5 9V5h4M19 9V5h-4"/>',
        'wand'        => '<path d="m5 19 9-9"/><path d="M14.5 4.5 16 7l2.5 1.5L16 10l-1.5 2.5L13 10l-2.5-1.5L13 7z"/><path d="M19 14.5l.8 1.7 1.7.8-1.7.8-.8 1.7-.8-1.7-1.7-.8 1.7-.8z"/>',
        'home'        => '<path d="m3.5 10.5 8.5-7 8.5 7V20a1.5 1.5 0 0 1-1.5 1.5h-14A1.5 1.5 0 0 1 3.5 20z"/><path d="M9.5 21.5v-7h5v7"/>',
        'inbox'       => '<path d="M3.5 12.5h4l1.5 3h6l1.5-3h4"/><path d="M5.4 5.3 3.5 12.5V18a1.5 1.5 0 0 0 1.5 1.5h14a1.5 1.5 0 0 0 1.5-1.5v-5.5l-1.9-7.2a1.5 1.5 0 0 0-1.4-1.1H6.8a1.5 1.5 0 0 0-1.4 1.1z"/>',
    ];

    public static function svg(string $name, int $groesse = 20, string $klasse = ''): string
    {
        $pfad = self::PFADE[$name] ?? self::PFADE['info'];
        return sprintf(
            '<svg class="ico%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" '
            . 'stroke="currentColor" stroke-width="1.75" stroke-linecap="round" '
            . 'stroke-linejoin="round" aria-hidden="true">%s</svg>',
            $klasse !== '' ? ' ' . $klasse : '',
            $groesse,
            $groesse,
            $pfad
        );
    }

    public static function existiert(string $name): bool
    {
        return isset(self::PFADE[$name]);
    }

    /** @return string[] */
    public static function namen(): array
    {
        return array_keys(self::PFADE);
    }
}
