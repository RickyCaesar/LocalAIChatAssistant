#!/usr/bin/env python3
"""Local speech-to-text via faster-whisper (CPU).

Usage:
    transcribe.py --file AUDIO --model small --language id

Prints a single JSON object to stdout:
    {"text": "...", "language": "id", "duration": 123.4, "model": "small"}
Errors are printed to stderr and exit with a non-zero status.
"""
import argparse
import json
import sys


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--file", required=True)
    parser.add_argument("--model", default="small")
    parser.add_argument("--language", default="")
    args = parser.parse_args()

    try:
        from faster_whisper import WhisperModel
    except Exception as exc:  # pragma: no cover
        print(f"faster-whisper unavailable: {exc}", file=sys.stderr)
        return 2

    language = args.language.strip() or None
    if language == "auto":
        language = None

    try:
        model = WhisperModel(args.model, device="cpu", compute_type="int8")
        segments, info = model.transcribe(
            args.file,
            language=language,
            vad_filter=True,
            beam_size=1,
        )
        parts = [seg.text.strip() for seg in segments if seg.text and seg.text.strip()]
    except Exception as exc:  # pragma: no cover
        print(f"transcription failed: {exc}", file=sys.stderr)
        return 1

    print(json.dumps({
        "text": " ".join(parts).strip(),
        "language": getattr(info, "language", language),
        "duration": round(float(getattr(info, "duration", 0.0)), 2),
        "model": args.model,
    }))
    return 0


if __name__ == "__main__":
    sys.exit(main())
