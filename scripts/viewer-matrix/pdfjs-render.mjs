// Renders every page of each PDF with pdf.js (Firefox's engine) to PNG and
// reports errors, warnings and blank pages.
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';
import { createCanvas } from '@napi-rs/canvas';
import fs from 'node:fs';
import path from 'node:path';

const [dir, out] = process.argv.slice(2);
fs.mkdirSync(out, { recursive: true });
for (const file of fs.readdirSync(dir).filter(f => f.endsWith('.pdf')).sort()) {
    const warnings = [];
    const origWarn = console.warn; const origLog = console.log;
    console.warn = (...a) => warnings.push(a.join(' ')); console.log = (...a) => warnings.push(a.join(' '));
    let status = 'ok', pages = 0, blank = 0;
    try {
        const doc = await getDocument({ data: new Uint8Array(fs.readFileSync(path.join(dir, file))), disableFontFace: true, verbosity: 1, standardFontDataUrl: new URL('./node_modules/pdfjs-dist/standard_fonts/', import.meta.url).pathname }).promise;
        pages = doc.numPages;
        for (let i = 1; i <= pages; i++) {
            const page = await doc.getPage(i);
            const vp = page.getViewport({ scale: 1 });
            const canvas = createCanvas(Math.ceil(vp.width), Math.ceil(vp.height));
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
            await page.render({ canvasContext: ctx, viewport: vp, canvas }).promise;
            const data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
            let ink = 0; for (let p = 0; p < data.length; p += 4) if (data[p] < 200 || data[p + 1] < 200 || data[p + 2] < 200) ink++;
            if (ink < 50) blank++;
            fs.writeFileSync(path.join(out, `${file.replace('.pdf', '')}-${i}.png`), canvas.toBuffer('image/png'));
        }
    } catch (e) { status = 'ERROR ' + e.message; }
    console.warn = origWarn; console.log = origLog;
    console.log(`${file}\t${status}\tpages=${pages}\tblank=${blank}\twarnings=${warnings.length}${warnings.length ? ' — ' + [...new Set(warnings)].slice(0, 3).join(' | ') : ''}`);
}
