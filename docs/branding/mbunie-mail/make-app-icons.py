# Makes the app icons from the full MbunieMail logo (mbunie-mail-logo-full.png):
#   app/windows/runner/resources/app_icon.ico  window, taskbar, Start menu, installer
#   app/assets/tray_icon.ico                   notification-area icon
#   app/android/app/src/main/res/mipmap-*/     Android launcher (adaptive + legacy)
#   app/assets/logo.png, logo_mark.png         in-app logo (lib/shared/brand_logo.dart)
# The ring of envelopes cannot be read at 16-32 px, so those sizes show the centre "M".
#   python docs/branding/mbunie-mail/make-app-icons.py
from pathlib import Path
from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parents[3]
SRC = Image.open(Path(__file__).with_name('mbunie-mail-logo-full.png')).convert('RGB')
FULL = (40, 40, 1214, 1214)        # the whole logo, squared around its content
MARK = (265, 340, 995, 905)        # the centre "M" only (not square; padded)
RES = ROOT / 'app/android/app/src/main/res'


def logo(box, size):
    part = SRC.crop(box)
    side = max(part.size)
    square = Image.new('RGB', (side, side), (255, 255, 255))
    square.paste(part, ((side - part.width) // 2, (side - part.height) // 2))
    return square.resize((size, size), Image.LANCZOS).convert('RGBA')


def tile(size, box, inset):
    """The logo on a white rounded square, as on other Windows app icons."""
    out = Image.new('RGBA', (size, size), (0, 0, 0, 0))
    mask = Image.new('L', (size, size), 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, size - 1, size - 1], radius=size * 0.22, fill=255)
    out.paste((255, 255, 255, 255), (0, 0), mask)
    inner = round(size * (1 - 2 * inset))
    out.paste(logo(box, inner), ((size - inner) // 2,) * 2)
    out.putalpha(Image.composite(out.getchannel('A'), mask, mask))
    return out


def windows_ico(path):
    frames = {s: tile(s, MARK if s <= 32 else FULL, 0.08 if s <= 32 else 0.04) for s in (16, 20, 24, 32, 40, 48, 64, 128, 256)}
    big = frames[256]
    big.save(path, sizes=[(s, s) for s in frames], append_images=[frames[s] for s in frames if s != 256])


windows_ico(ROOT / 'app/windows/runner/resources/app_icon.ico')
windows_ico(ROOT / 'app/assets/tray_icon.ico')

for density, scale in {'mdpi': 1, 'hdpi': 1.5, 'xhdpi': 2, 'xxhdpi': 3, 'xxxhdpi': 4}.items():
    d = RES / f'mipmap-{density}'
    # Adaptive foreground: 108dp, logo inside the 66dp area every launcher mask keeps.
    fg_size = round(108 * scale)
    fg = Image.new('RGBA', (fg_size, fg_size), (255, 255, 255, 255))
    inner = round(66 * scale)
    fg.paste(logo(FULL, inner), ((fg_size - inner) // 2,) * 2)
    fg.convert('RGB').save(d / 'ic_launcher_foreground.png', optimize=True)
    # Legacy icon (Android 7 and older never run this app, but launchers may read it).
    tile(round(48 * scale), FULL, 0.04).save(d / 'ic_launcher.png', optimize=True)

logo(FULL, 512).convert('RGB').save(ROOT / 'app/assets/logo.png', optimize=True)
logo(MARK, 256).convert('RGB').save(ROOT / 'app/assets/logo_mark.png', optimize=True)
