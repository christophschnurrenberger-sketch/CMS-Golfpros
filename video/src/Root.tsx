import React from 'react';
import {Composition, Still} from 'remotion';
import {Vorschaubild} from './Vorschaubild';
import {FPS, GESAMT, Werbevideo, werbevideoStandard} from './Werbevideo';
import {GESAMT2, Werbevideo2} from './Werbevideo2';

export const Root: React.FC = () => (
  <>
    {/* Hochformat 9:16 – Reels, TikTok, YouTube Shorts */}
    <Composition id="TeePilot" component={Werbevideo} durationInFrames={GESAMT} fps={FPS} width={1080} height={1920} defaultProps={werbevideoStandard} />
    <Composition id="TeePilotLang" component={Werbevideo2} durationInFrames={GESAMT2} fps={FPS} width={1080} height={1920} defaultProps={werbevideoStandard} />
    {/* Querformat 16:9 – YouTube und die eigene Website. Dieselbe Fassung, die Szenen ordnen sich selbst um. */}
    <Composition id="TeePilotQuer" component={Werbevideo2} durationInFrames={GESAMT2} fps={FPS} width={1920} height={1080} defaultProps={werbevideoStandard} />
    {/* Vorschaubild: 1920×1080 für die Website, mit --scale=0.6667 als 1280×720 für YouTube */}
    <Still id="Vorschaubild" component={Vorschaubild} width={1920} height={1080} />
  </>
);
