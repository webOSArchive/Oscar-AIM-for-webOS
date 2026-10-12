"""
Draws the webOS Chat account icons: two speech bubbles in webOS's blue and amber, outlined and
glossed like Palm's account icons. Run: python3 draw-icons.py org.webosarchive.chat/images
(needs Pillow); it writes webos-chat-48x48.png and webos-chat-32x32.png.
"""
import sys
from PIL import Image, ImageDraw, ImageFilter, ImageChops
S = 768  # drawing canvas; scaled down to 48 and 32

def bubble(box, tail, flip=False):
    """A rounded speech bubble mask: box (x0,y0,x1,y1), tail tip (x,y)."""
    m = Image.new("L", (S, S), 0)
    d = ImageDraw.Draw(m)
    x0, y0, x1, y1 = box
    d.rounded_rectangle(box, radius=(y1 - y0) * 0.42, fill=255)
    bx = x0 + (x1 - x0) * (0.70 if flip else 0.30)
    d.polygon([(bx - 70, y1 - 60), (bx + 70, y1 - 60), tail], fill=255)
    return m

def gradient(top, bottom):
    g = Image.new("RGBA", (S, S))
    d = ImageDraw.Draw(g)
    for y in range(S):
        t = y / (S - 1)
        d.line([(0, y), (S, y)], fill=tuple(int(top[i] + (bottom[i] - top[i]) * t) for i in range(3)) + (255,))
    return g

def layer(mask, top, bottom, outline):
    out = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    edge = mask.filter(ImageFilter.MaxFilter(25))
    out.paste(Image.new("RGBA", (S, S), outline + (255,)), (0, 0), edge)
    out.paste(gradient(top, bottom), (0, 0), mask)
    # gloss: a lighter band over the upper part of the shape
    inner = mask.filter(ImageFilter.MinFilter(31))
    band = Image.new("L", (S, S), 0)
    bb = inner.getbbox()
    ImageDraw.Draw(band).ellipse((bb[0] - 40, bb[1] - (bb[3] - bb[1]) * 0.9, bb[2] + 40, bb[1] + (bb[3] - bb[1]) * 0.5), fill=90)
    gloss = ImageChops.multiply(band, inner)
    out.paste(Image.new("RGBA", (S, S), (255, 255, 255, 255)), (0, 0), gloss)
    return out, edge

back, back_edge = layer(bubble((250, 120, 720, 440), (600, 540), flip=True), (150, 205, 255), (60, 130, 215), (20, 55, 110))
front, front_edge = layer(bubble((50, 300, 540, 630), (150, 735)), (255, 214, 90), (232, 150, 20), (110, 60, 0))

img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
shadow = ImageChops.lighter(back_edge, front_edge).filter(ImageFilter.GaussianBlur(18))
sh = Image.new("RGBA", (S, S), (0, 0, 0, 0))
sh.paste(Image.new("RGBA", (S, S), (0, 0, 0, 110)), (0, 18), shadow)
img = Image.alpha_composite(img, sh)
img = Image.alpha_composite(img, back)
img = Image.alpha_composite(img, front)
out = sys.argv[1]
img.resize((48, 48), Image.LANCZOS).save(out + "/webos-chat-48x48.png", optimize=True)
img.resize((32, 32), Image.LANCZOS).save(out + "/webos-chat-32x32.png", optimize=True)
