import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// The MbunieMail logo, the same image as the app icons
/// (docs/branding/mbunie-mail/make-app-icons.py). The ring of envelopes cannot be
/// read when small, so 48 px and below show the centre "M" only.
class MbunieMailLogo extends StatelessWidget {
  const MbunieMailLogo({super.key, this.size = 96});

  final double size;

  static const _full = 'assets/logo.png';
  static const _mark = 'assets/logo_mark.png';

  /// The logo as a square PNG on white, e.g. for a notification's large icon
  /// (Android crops it to the launcher shape).
  static Future<Uint8List> png(int size) async {
    final data = await rootBundle.load(size <= 48 ? _mark : _full);
    final codec = await ui.instantiateImageCodec(
      data.buffer.asUint8List(),
      targetWidth: size,
      targetHeight: size,
    );
    final image = (await codec.getNextFrame()).image;
    final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
    image.dispose();
    return bytes!.buffer.asUint8List();
  }

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: 'MbunieMail',
      image: true,
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.circular(size * 0.24),
          boxShadow: [
            BoxShadow(
              color: const Color(0xFF2B2F86).withValues(alpha: 0.25),
              blurRadius: size * 0.3,
              offset: Offset(0, size * 0.12),
            ),
          ],
        ),
        clipBehavior: Clip.antiAlias,
        padding: EdgeInsets.all(size * 0.06),
        child: Image.asset(
          size <= 48 ? _mark : _full,
          fit: BoxFit.contain,
          filterQuality: FilterQuality.medium,
        ),
      ),
    );
  }
}
