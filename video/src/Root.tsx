import React from 'react';
import {Composition} from 'remotion';
import {FPS, GESAMT, Werbevideo, werbevideoStandard} from './Werbevideo';

export const Root: React.FC = () => (
  <Composition
    id="TeePilot"
    component={Werbevideo}
    durationInFrames={GESAMT}
    fps={FPS}
    width={1080}
    height={1920}
    defaultProps={werbevideoStandard}
  />
);
