#!/usr/bin/env bash
# The automated columns of docs/en/VIEWER-MATRIX.md: renders every torture
# document with pdf.js (Firefox), PDFium (Chrome) and — on macOS — Quartz
# (Preview, via Quick Look). poppler and Ghostscript run in CI
# (scripts/conformance/torture-smoke.sh).
#
#   php examples/torture/generate.php build/torture
#   bash scripts/viewer-matrix/run.sh build/torture
#
# Renders land in <dir>/pdfjs, <dir>/pdfium and <dir>/quartz; each engine
# prints one line per document: ok/ERROR, pages, blank pages, warnings.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
DIR="${1:?usage: run.sh <dir with t*.pdf>}"
WORK="${VIEWER_MATRIX_WORK:-$HERE/work}"
mkdir -p "$WORK"

echo "== pdf.js"
if [ ! -d "$WORK/node_modules/pdfjs-dist" ]; then
    (cd "$WORK" && echo "{\"name\": \"viewer-matrix\", \"private\": true}" > package.json && npm install --silent pdfjs-dist @napi-rs/canvas)
fi
cp "$HERE/pdfjs-render.mjs" "$WORK/"
node "$WORK/pdfjs-render.mjs" "$DIR" "$DIR/pdfjs"

echo "== PDFium"
if [ ! -x "$WORK/venv/bin/python" ]; then
    python3 -m venv "$WORK/venv"
    "$WORK/venv/bin/pip" install -q pypdfium2 pillow
fi
"$WORK/venv/bin/python" -I "$HERE/pdfium-render.py" "$DIR" "$DIR/pdfium"

if command -v qlmanage >/dev/null; then
    echo "== Quartz"
    mkdir -p "$DIR/quartz"
    for f in "$DIR"/t*.pdf; do
        qlmanage -t -s 900 -o "$DIR/quartz" "$f" >/dev/null 2>&1
        [ -s "$DIR/quartz/$(basename "$f").png" ] && echo "$(basename "$f")	ok" || echo "$(basename "$f")	FAIL"
    done
fi
