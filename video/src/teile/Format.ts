/**
 * Hoch (9:16, Reels/TikTok/Shorts) oder quer (16:9, YouTube/Website)?
 * Die Szenen fragen hier, statt zwei Fassungen jeder Szene zu pflegen.
 */
import {useVideoConfig} from 'remotion';

export const useQuer = () => {
  const {width, height} = useVideoConfig();
  return width > height;
};
