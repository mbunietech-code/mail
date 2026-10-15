import 'package:flutter/material.dart';

/// MbuniTech brand on Material 3. Colour pairs meet WCAG 2.2 AA contrast (SRS §11).
class AppTheme {
  static const brandBlue = Color(0xFF2456D3);

  /// Distinct, AA-contrast avatar colours (white text) chosen by a stable hash.
  static const avatarColors = [
    Color(0xFF1D4ED8),
    Color(0xFFC2410C),
    Color(0xFF0F766E),
    Color(0xFF7C3AED),
    Color(0xFFBE185D),
    Color(0xFF15803D),
    Color(0xFF4338CA),
    Color(0xFFB45309),
  ];

  static Color avatarColor(String seed) {
    var h = 0;
    for (final c in seed.toLowerCase().codeUnits) {
      h = (h * 31 + c) & 0x7fffffff;
    }
    return avatarColors[h % avatarColors.length];
  }

  static ThemeData light() => _base(
    ColorScheme.fromSeed(
      seedColor: brandBlue,
      primary: brandBlue,
      surface: const Color(0xFFF7F9FC),
      surfaceContainerLowest: Colors.white,
      surfaceContainerLow: const Color(0xFFF2F5FB),
      surfaceContainer: const Color(0xFFEAF0FA),
      surfaceContainerHigh: const Color(0xFFE3EAF7),
      secondaryContainer: const Color(0xFFD3E3FD),
      onSecondaryContainer: const Color(0xFF0B2A63),
    ),
  );

  static ThemeData dark() => _base(
    ColorScheme.fromSeed(
      seedColor: brandBlue,
      brightness: Brightness.dark,
      surface: const Color(0xFF111418),
      surfaceContainerLowest: const Color(0xFF0C0E11),
      surfaceContainerLow: const Color(0xFF181C22),
      surfaceContainer: const Color(0xFF1D2229),
      surfaceContainerHigh: const Color(0xFF242A32),
    ),
  );

  static ThemeData _base(ColorScheme scheme) {
    final pill = RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(28),
    );
    return ThemeData(
      colorScheme: scheme,
      useMaterial3: true,
      scaffoldBackgroundColor: scheme.surface,
      visualDensity: VisualDensity.standard,
      materialTapTargetSize:
          MaterialTapTargetSize.padded, // ≥48dp touch targets
      pageTransitionsTheme: const PageTransitionsTheme(
        builders: {
          TargetPlatform.android: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.windows: FadeForwardsPageTransitionsBuilder(),
          TargetPlatform.linux: FadeForwardsPageTransitionsBuilder(),
        },
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: scheme.surface,
        surfaceTintColor: Colors.transparent,
        scrolledUnderElevation: 0,
        centerTitle: false,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: scheme.surfaceContainerLowest,
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(14),
          borderSide: BorderSide(color: scheme.outlineVariant),
        ),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 16,
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(64, 50),
          shape: pill,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(64, 48),
          shape: pill,
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(shape: pill),
      ),
      floatingActionButtonTheme: FloatingActionButtonThemeData(
        backgroundColor: scheme.primaryContainer,
        foregroundColor: scheme.onPrimaryContainer,
        elevation: 2,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      ),
      cardTheme: CardThemeData(
        elevation: 0,
        color: scheme.surfaceContainerLow,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        margin: EdgeInsets.zero,
      ),
      chipTheme: ChipThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        side: BorderSide(color: scheme.outlineVariant),
      ),
      navigationDrawerTheme: NavigationDrawerThemeData(
        backgroundColor: scheme.surfaceContainerLow,
        indicatorColor: scheme.secondaryContainer,
        indicatorShape: pill,
        tileHeight: 52,
      ),
      drawerTheme: DrawerThemeData(
        backgroundColor: scheme.surfaceContainerLow,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.horizontal(right: Radius.circular(24)),
        ),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: scheme.surfaceContainerLow,
        showDragHandle: true,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
        ),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: scheme.surfaceContainerLow,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(28)),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
      listTileTheme: ListTileThemeData(
        minVerticalPadding: 10,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      ),
      dividerTheme: DividerThemeData(color: scheme.outlineVariant, space: 1),
    );
  }
}
