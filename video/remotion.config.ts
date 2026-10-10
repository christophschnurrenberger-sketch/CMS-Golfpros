/**
 * Einstellungen für `remotion render` und `remotion studio`.
 */
import {Config} from '@remotion/cli/config';
import fs from 'node:fs';

/*
 * Remotion lädt sonst ein eigenes Chrome Headless Shell aus dem Netz. Liegt
 * schon eines bereit – etwa das von Playwright –, wird das genommen.
 * Anderer Ort: REMOTION_CHROME=/pfad/zu/headless_shell npm run rendern
 */
const chrome =
  process.env.REMOTION_CHROME ?? '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell';
if (fs.existsSync(chrome)) {
  Config.setBrowserExecutable(chrome);
}

Config.setVideoImageFormat('jpeg');
Config.setJpegQuality(95);
Config.setCodec('h264');
// Niedrige CRF: Die Screens sind Text auf hellem Grund, da sieht man Blockbildung sofort.
Config.setCrf(16);
Config.setPixelFormat('yuv420p');
// BT.709 mit begrenztem Bereich – so erwarten es Telefone und die Plattformen.
// Ohne das schreibt Remotion vollen Bereich (yuvj420p), und manche Player
// verschieben dann die Farben: Pine wird schwarz, Chalk zu weiß.
Config.setColorSpace('bt709');
