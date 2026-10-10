import React from 'react';
import {Composition} from 'remotion';
import {FPS, GESAMT, Werbevideo, werbevideoStandard} from './Werbevideo';
import {GESAMT2, Werbevideo2} from './Werbevideo2';

export const Root: React.FC = () => (
  <>
    {/* 30 Sekunden, ohne Ton */}
    <Composition
      id="TeePilot"
      component={Werbevideo}
      durationInFrames={GESAMT}
      fps={FPS}
      width={1080}
      height={1920}
      defaultProps={werbevideoStandard}
    />
    {/* 45 Sekunden, mehr Desktop, mit Musik */}
    <Composition
      id="TeePilotLang"
      component={Werbevideo2}
      durationInFrames={GESAMT2}
      fps={FPS}
      width={1080}
      height={1920}
      defaultProps={werbevideoStandard}
    />
  </>
);
