"""
Hört Sprachaufnahmen ab: schreibt mit Whisper (sherpa-onnx, offline) auf,
was in jeder WAV-Datei zu verstehen ist – eine JSON-Zeile je Datei.

    python3 ton/abhoeren.py MODELLORDNER a.wav b.wav …

ton/sprecher.mjs --takes N ruft das auf, um von mehreren Takes je Satz den
deutlichsten zu nehmen. Modell: sherpa-onnx-whisper-small (siehe README,
Abschnitt „Sprecher“); gebraucht werden small-encoder.int8.onnx,
small-decoder.int8.onnx und small-tokens.txt.
"""
import json
import sys
import wave

import numpy as np
import sherpa_onnx

ordner, dateien = sys.argv[1], sys.argv[2:]
erkenner = sherpa_onnx.OfflineRecognizer.from_whisper(
    encoder=f'{ordner}/small-encoder.int8.onnx',
    decoder=f'{ordner}/small-decoder.int8.onnx',
    tokens=f'{ordner}/small-tokens.txt',
    language='de',
    task='transcribe',
    num_threads=4,
)

for datei in dateien:
    with wave.open(datei) as w:
        rate, kanaele = w.getframerate(), w.getnchannels()
        ton = np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16).astype(np.float32) / 32768
    if kanaele > 1:
        ton = ton.reshape(-1, kanaele).mean(axis=1)
    # Stille davor und danach – sonst verschluckt Whisper gern das letzte Wort.
    ton = np.concatenate([np.zeros(int(rate * 0.3)), ton, np.zeros(int(rate * 0.5))]).astype(np.float32)
    strom = erkenner.create_stream()
    strom.accept_waveform(rate, ton)
    erkenner.decode_stream(strom)
    print(json.dumps({'datei': datei, 'text': strom.result.text.strip()}, ensure_ascii=False), flush=True)
