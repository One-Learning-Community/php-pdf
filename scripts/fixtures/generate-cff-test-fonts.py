"""Build the tiny CFF-flavoured OpenType fonts in tests/fixtures/fonts.

The glyphs are drawn here (plain polygons), so the fonts carry no third-party
design and are covered by the repository licence.

  MiniCff-Regular.otf     name-keyed CFF: CID == glyph index
  MiniCffCid-Regular.otf  CID-keyed CFF (ROS Adobe-Identity-0) whose charset
                          maps glyph i to CID 100 + 2i, so CID != glyph index

Usage: python3 -m pip install fonttools
       python3 scripts/fixtures/generate-cff-test-fonts.py tests/fixtures/fonts
"""
import sys
from fontTools.fontBuilder import FontBuilder
from fontTools.pens.t2CharStringPen import T2CharStringPen
from fontTools.cffLib import FDArrayIndex, FontDict, FDSelect, PrivateDict

out = sys.argv[1]

# (glyph name, codepoint, advance, polygons)
def rect(x0, y0, x1, y1):
    return [(x0, y0), (x1, y0), (x1, y1), (x0, y1)]

GLYPHS = [
    ('.notdef', None, 500, [rect(50, 0, 450, 700), rect(100, 50, 400, 650)[::-1]]),
    ('space', 0x20, 250, []),
    ('H', 0x48, 640, [rect(80, 0, 180, 700), rect(460, 0, 560, 700), rect(180, 300, 460, 400)]),
    ('W', 0x57, 900, [[(40, 700), (140, 700), (250, 150), (400, 700), (500, 700), (650, 150), (760, 700), (860, 700), (700, 0), (600, 0), (450, 500), (300, 0), (200, 0)]]),
    ('d', 0x64, 560, [rect(400, 0, 480, 720), rect(80, 0, 400, 80), rect(80, 80, 160, 440), rect(160, 360, 400, 440)]),
    ('e', 0x65, 540, [rect(60, 0, 140, 500), rect(140, 0, 480, 80), rect(140, 220, 480, 290), rect(140, 420, 480, 500), rect(400, 290, 480, 420)]),
    ('l', 0x6C, 240, [rect(80, 0, 160, 720)]),
    ('o', 0x6F, 560, [rect(60, 0, 500, 500), rect(140, 80, 420, 420)[::-1]]),
    ('r', 0x72, 380, [rect(80, 0, 160, 500), rect(160, 420, 340, 500)]),
    ('Pe', 0x41F, 640, [rect(80, 0, 180, 700), rect(460, 0, 560, 700), rect(180, 600, 460, 700)]),
]


def charstrings():
    out = {}
    for name, _cp, adv, polys in GLYPHS:
        pen = T2CharStringPen(adv, None)
        for poly in polys:
            pen.moveTo(poly[0])
            for p in poly[1:]:
                pen.lineTo(p)
            pen.closePath()
        out[name] = pen.getCharString()
    return out


def build(psname, family, cid_keyed=False):
    names = [g[0] for g in GLYPHS]
    fb = FontBuilder(1000, isTTF=False)
    fb.setupGlyphOrder(names)
    fb.setupCharacterMap({cp: name for name, cp, _a, _p in GLYPHS if cp is not None})
    fb.setupCFF(psname, {'FullName': psname}, charstrings(), {})
    fb.setupHorizontalMetrics({name: (adv, 0) for name, _cp, adv, _p in GLYPHS})
    fb.setupHorizontalHeader(ascent=800, descent=-200)
    fb.setupNameTable({'familyName': family, 'styleName': 'Regular', 'psName': psname})
    fb.setupOS2(sTypoAscender=800, sTypoDescender=-200, usWinAscent=800, usWinDescent=200, sCapHeight=700, sxHeight=500)
    fb.setupPost()
    font = fb.font
    if cid_keyed:
        cff = font['CFF '].cff
        top = cff.topDictIndex[0]
        cs = top.CharStrings
        # CIDs deliberately differ from glyph indices: gid i -> CID 100 + 2i.
        mapping = {n: ('.notdef' if i == 0 else 'cid%05d' % (100 + 2 * i)) for i, n in enumerate(names)}
        cs.charStrings = {mapping[k]: v for k, v in cs.charStrings.items()}
        font.setGlyphOrder([mapping[n] for n in names])
        top.charset = [mapping[n] for n in names]
        top.ROS = ('Adobe', 'Identity', 0)
        top.CIDCount = 100 + 2 * len(names)
        fd = FontDict()
        fd.setCFF2(False)
        fd.Private = top.Private
        fd.FontMatrix = [0.001, 0, 0, 0.001, 0, 0]
        fdarray = FDArrayIndex()
        fdarray.append(fd)
        top.FDArray = fdarray
        top.FDSelect = FDSelect()
        top.FDSelect.format = 3
        top.FDSelect.gidArray = [0] * len(names)
        for cs_item in cs.values():
            cs_item.private = fd.Private
        cs.fdSelect = top.FDSelect
        cs.fdArray = fdarray
        del top.Private
        # Glyph names in other tables.
        cmap = font['cmap']
        for sub in cmap.tables:
            sub.cmap = {k: mapping[v] for k, v in sub.cmap.items()}
        hm = font['hmtx']
        hm.metrics = {mapping[k]: v for k, v in hm.metrics.items()}
        font['post'].formatType = 3.0
    return font


build('MiniCff-Regular', 'MiniCff').save(f'{out}/MiniCff-Regular.otf')
build('MiniCffCid-Regular', 'MiniCffCid', cid_keyed=True).save(f'{out}/MiniCffCid-Regular.otf')
