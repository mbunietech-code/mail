# Draws mbunie-mail-icon.svg with Pillow and writes the Windows .ico (window, taskbar, tray).
# python docs/branding/mbunie-mail/make-windows-icon.py app/windows/runner/resources/app_icon.ico
# then copy it to app/assets/tray_icon.ico. Also writes <out>.png for a preview.
import math, sys
from PIL import Image, ImageDraw

K = 4                      # supersampling
N = 512 * K
def T(x, y):               # inner coords -> canvas: translate(52,62) scale(0.6)
    return ((52 + 0.6 * x) * K, (62 + 0.6 * y) * K)
def hexc(h): return tuple(int(h[i:i+2], 16) for i in (1, 3, 5)) + (255,)

def gradient(stops, x1, x2):
    img = Image.new('RGBA', (N, 1))
    a, b = T(x1, 0)[0], T(x2, 0)[0]
    for px in range(N):
        t = min(1, max(0, (px - a) / (b - a)))
        for (o0, c0), (o1, c1) in zip(stops, stops[1:]):
            if o0 <= t <= o1:
                f = 0 if o1 == o0 else (t - o0) / (o1 - o0)
                img.putpixel((px, 0), tuple(round(c0[i] + (c1[i] - c0[i]) * f) for i in range(4)))
                break
    return img.resize((N, N))

D, L, M = hexc('#2B2F86'), hexc('#1FA3E6'), hexc('#1B6FC0')
g1 = gradient([(0, D), (.5, D), (.78, L), (1, M)], 75, 345)
g2 = gradient([(0, D), (.42, D), (.72, L), (1, D)], 300, 570)
g3 = gradient([(0, L), (.55, D), (1, D)], 520, 670)

out = Image.new('RGBA', (N, N), (0, 0, 0, 0))
d = ImageDraw.Draw(out)
d.rounded_rectangle([0, 0, N - 1, N - 1], radius=112 * K, fill=(255, 255, 255, 255))

def rrect(x, y, w, h, r, fill):
    (a, b), (c, e) = T(x, y), T(x + w, y + h)
    d.rounded_rectangle([a, b, c, e], radius=r * 0.6 * K, fill=fill)
rrect(78, 132, 98, 70, 12, L)
rrect(64, 122, 98, 70, 12, D)
pts = [T(76, 136), T(113, 166), T(150, 136)]
w = round(9 * 0.6 * K)
d.line(pts, fill=(255, 255, 255, 255), width=w, joint='curve')
for p in (pts[0], pts[-1]):
    d.ellipse([p[0] - w / 2, p[1] - w / 2, p[0] + w / 2, p[1] + w / 2], fill=(255, 255, 255, 255))

def stroke(points, grad):
    mask = Image.new('L', (N, N), 0)
    m = ImageDraw.Draw(mask)
    p = [T(*q) for q in points]
    r = 75 * 0.6 * K
    m.line(p, fill=255, width=round(2 * r), joint='curve')
    for q in p:
        m.ellipse([q[0] - r, q[1] - r, q[0] + r, q[1] + r], fill=255)
    out.paste(grad, (0, 0), mask)
stroke([(270, 195), (270, 235), (150, 430), (150, 470)], g1)
stroke([(495, 195), (495, 235), (375, 430), (375, 470)], g2)

poly = [(670, 265), (670, 470)]
poly += [(595 + 75 * math.cos(a), 470 + 75 * math.sin(a)) for a in [i * math.pi / 64 for i in range(65)]]
poly += [(520, 432)]
poly += [((1 - t) ** 2 * 520 + 2 * (1 - t) * t * 520 + t * t * 529, (1 - t) ** 2 * 432 + 2 * (1 - t) * t * 418 + t * t * 407) for t in [i / 16 for i in range(17)]]
mask = Image.new('L', (N, N), 0)
ImageDraw.Draw(mask).polygon([T(*q) for q in poly], fill=255)
out.paste(g3, (0, 0), mask)

img = out.resize((256, 256), Image.LANCZOS)
img.save(sys.argv[1] + '.png')
img.save(sys.argv[1], sizes=[(16, 16), (20, 20), (24, 24), (32, 32), (40, 40), (48, 48), (64, 64), (256, 256)])
