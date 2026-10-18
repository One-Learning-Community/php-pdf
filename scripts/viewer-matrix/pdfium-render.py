# Renders every page of each PDF with PDFium (Chrome's engine) and reports
# load errors and blank pages.
import sys, pathlib, pypdfium2 as pdfium
src, out = pathlib.Path(sys.argv[1]), pathlib.Path(sys.argv[2])
out.mkdir(parents=True, exist_ok=True)
print('pdfium', pdfium.PDFIUM_INFO)
for f in sorted(src.glob('t*.pdf')):
    try:
        doc = pdfium.PdfDocument(str(f))
        blank = 0
        for i, page in enumerate(doc, 1):
            img = page.render(scale=1, may_draw_forms=True).to_pil().convert('RGB')
            pixels = img.get_flattened_data() if hasattr(img, 'get_flattened_data') else img.getdata()
            ink = sum(1 for p in pixels if min(p) < 200)
            blank += ink < 50
            img.save(out / f'{f.stem}-{i}.png')
        print(f'{f.name}\tok\tpages={len(doc)}\tblank={blank}')
    except Exception as e:
        print(f'{f.name}\tERROR {e}')
