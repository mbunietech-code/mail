import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

/// The MbunieMail app icon (MbunieTech mark behind a centred envelope), drawn
/// from the same geometry as the Android adaptive icon
/// (android/app/src/main/res/drawable/ic_launcher_foreground.xml).
class MbunieMailLogo extends StatelessWidget {
  const MbunieMailLogo({super.key, this.size = 96});

  final double size;

  /// The logo as a square PNG on white, e.g. for a notification's large icon
  /// (Android crops it to the launcher shape).
  static Future<Uint8List> png(int size) async {
    final recorder = ui.PictureRecorder();
    final canvas = Canvas(recorder);
    final area = Size.square(size.toDouble());
    canvas.drawRect(Offset.zero & area, Paint()..color = Colors.white);
    _LogoPainter().paint(canvas, area);
    final image = await recorder.endRecording().toImage(size, size);
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
              color: _navy.withValues(alpha: 0.25),
              blurRadius: size * 0.3,
              offset: Offset(0, size * 0.12),
            ),
          ],
        ),
        clipBehavior: Clip.antiAlias,
        child: CustomPaint(painter: _LogoPainter()),
      ),
    );
  }
}

const _navy = Color(0xFF2B2F86);
const _sky = Color(0xFF1FA3E6);
const _blue = Color(0xFF1B6FC0);

class _LogoPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size size) {
    // Geometry lives in the adaptive icon's 108dp space (visible part 18..90).
    // Everything is mapped to pixels up front rather than via canvas.scale: the
    // mark's 150-unit strokes drawn under a tiny canvas scale tessellate badly.
    final k = size.width / 72;
    final m = 0.105 * k; // mark units -> pixels
    final x0 = (14.9 - 18) * k, y0 = (19.1 - 18) * k;
    final markToPx = Float64List.fromList([
      m, 0, 0, 0, //
      0, m, 0, 0, //
      0, 0, 1, 0, //
      x0, y0, 0, 1,
    ]);
    Shader gradient(double x1, double x2, List<Color> c, List<double> s) =>
        LinearGradient(
          colors: c,
          stops: s,
        ).createShader(Rect.fromLTRB(x0 + m * x1, 0, x0 + m * x2, 1));
    // Each segment is drawn as its own round-capped line: the caps overlap to
    // form the round joins. A single round-joined polyline this thick leaves
    // white slivers at the short end segment on Impeller.
    void stroke(List<Offset> points, Shader shader) {
      final paint = Paint()
        ..shader = shader
        ..strokeWidth = 150 * m
        ..strokeCap = StrokeCap.round;
      Offset px(Offset p) => Offset(x0 + m * p.dx, y0 + m * p.dy);
      for (var i = 1; i < points.length; i++) {
        canvas.drawLine(px(points[i - 1]), px(points[i]), paint);
      }
    }

    // MbunieTech mark, scaled to fill the icon.
    stroke(
      const [
        Offset(270, 195),
        Offset(270, 235),
        Offset(150, 430),
        Offset(150, 470),
      ],
      gradient(
        75,
        345,
        const [_navy, _navy, _sky, _blue],
        const [0, .5, .78, 1],
      ),
    );
    stroke(
      const [
        Offset(495, 195),
        Offset(495, 235),
        Offset(375, 430),
        Offset(375, 470),
      ],
      gradient(
        300,
        570,
        const [_navy, _navy, _sky, _navy],
        const [0, .42, .72, 1],
      ),
    );
    final drop = Path()
      ..moveTo(670, 265)
      ..lineTo(670, 470)
      ..arcToPoint(const Offset(520, 470), radius: const Radius.circular(75))
      ..lineTo(520, 432)
      ..quadraticBezierTo(520, 418, 529, 407)
      ..close();
    canvas.drawPath(
      drop.transform(markToPx),
      Paint()
        ..shader = gradient(
          520,
          670,
          const [_sky, _navy, _navy],
          const [0, .55, 1],
        ),
    );

    // Envelope in front, centred (36dp wide).
    final w = 36 * k, h = w * 400 / 560, r = Radius.circular(w * 60 / 560);
    final x = (54 - 18) * k - w / 2, y = (54 - 18) * k - h / 2;
    final border = Paint()
      ..color = Colors.white
      ..style = PaintingStyle.stroke
      ..strokeWidth = w * 0.05;
    for (final (offset, color) in [
      (Offset(w * 0.07, w * 0.055), _sky),
      (Offset.zero, _navy),
    ]) {
      final rect = RRect.fromRectAndRadius(
        Rect.fromLTWH(x, y, w, h).shift(offset),
        r,
      );
      canvas
        ..drawRRect(rect, Paint()..color = color)
        ..drawRRect(rect, border);
    }
    canvas.drawPath(
      Path()..addPolygon([
        Offset(x + 0.15 * w, y + 0.22 * h),
        Offset(x + w / 2, y + 0.6 * h),
        Offset(x + 0.85 * w, y + 0.22 * h),
      ], false),
      Paint()
        ..color = Colors.white
        ..style = PaintingStyle.stroke
        ..strokeWidth = w * 0.08
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round,
    );
  }

  @override
  bool shouldRepaint(_LogoPainter oldDelegate) => false;
}
