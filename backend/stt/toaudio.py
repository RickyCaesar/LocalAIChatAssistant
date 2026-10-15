#!/usr/bin/env python3
"""Extract the audio track of any media file (video/audio) to MP3 via PyAV.

Usage:
    toaudio.py --file INPUT --out OUTPUT.mp3

PyAV bundles the ffmpeg libraries, so no system ffmpeg is needed; it reuses the
same venv already installed for speech-to-text. Errors go to stderr with a
non-zero exit status.
"""
import argparse
import sys


def convert(src: str, dst: str) -> None:
    import av

    with av.open(src) as inp:
        if not inp.streams.audio:
            raise RuntimeError("Berkas tidak memiliki trek audio.")
        source = inp.streams.audio[0]
        # ponytail: selalu resample ke 44100 Hz/stereo agar libmp3lame yang
        # menolak laju sampling langka tetap jalan. Ganti dengan rate=source.rate
        # bila ingin mempertahankan laju asli.
        resampler = av.AudioResampler(format="s16p", layout="stereo", rate=44100)
        total = float(inp.duration or 0)  # microseconds; 0 bila tak diketahui
        with av.open(dst, mode="w", format="mp3") as out:
            stream = out.add_stream("libmp3lame", rate=44100)
            for frame in inp.decode(audio=0):
                for rf in resampler.resample(frame):
                    for packet in stream.encode(rf):
                        out.mux(packet)
                if total > 0 and frame.time is not None:
                    # Progres dibaca job Laravel dari stderr ("PROGRESS <pct>").
                    pct = int(min(99, (frame.time * 1_000_000 / total) * 100))
                    print(f"PROGRESS {max(1, pct)}", file=sys.stderr, flush=True)
            for packet in stream.encode(None):
                out.mux(packet)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--file", required=True)
    parser.add_argument("--out", required=True)
    args = parser.parse_args()

    try:
        convert(args.file, args.out)
    except Exception as exc:
        print(f"conversion failed: {exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
