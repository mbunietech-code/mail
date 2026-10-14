import 'package:flutter/material.dart';

/// MbuniTech brand: professional blue on white, neutral surfaces, no gradients.
/// Colour pairs meet WCAG 2.2 AA contrast (SRS §11).
class AppTheme {
  static const brandBlue = Color(0xFF1A4FA0);

  static ThemeData light() => _base(
    ColorScheme.fromSeed(
      seedColor: brandBlue,
      primary: brandBlue,
      surface: Colors.white,
    ),
  );

  static ThemeData dark() => _base(
    ColorScheme.fromSeed(seedColor: brandBlue, brightness: Brightness.dark),
  );

  static ThemeData _base(ColorScheme scheme) => ThemeData(
    colorScheme: scheme,
    useMaterial3: true,
    visualDensity: VisualDensity.standard,
    materialTapTargetSize: MaterialTapTargetSize.padded, // ≥48dp touch targets
    inputDecorationTheme: const InputDecorationTheme(
      border: OutlineInputBorder(),
    ),
    listTileTheme: const ListTileThemeData(minVerticalPadding: 10),
    dividerTheme: DividerThemeData(color: scheme.outlineVariant, space: 1),
  );
}
