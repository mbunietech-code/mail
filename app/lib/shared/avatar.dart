import 'package:flutter/material.dart';

import '../core/theme.dart';

/// Coloured initial avatar, stable per address (like Gmail's letter avatars).
class LetterAvatar extends StatelessWidget {
  const LetterAvatar({
    super.key,
    required this.name,
    required this.email,
    this.radius = 20,
    this.selected = false,
  });
  final String name;
  final String email;
  final double radius;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final source = name.trim().isNotEmpty ? name.trim() : email;
    final letter = source.isEmpty ? '?' : source.characters.first.toUpperCase();
    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 200),
      transitionBuilder: (child, a) => ScaleTransition(scale: a, child: child),
      child: CircleAvatar(
        key: ValueKey(selected),
        radius: radius,
        backgroundColor: selected
            ? scheme.primary
            : AppTheme.avatarColor(email),
        child: selected
            ? Icon(Icons.check, color: scheme.onPrimary, size: radius)
            : Text(
                letter,
                style: TextStyle(
                  color: Colors.white,
                  fontWeight: FontWeight.w600,
                  fontSize: radius * 0.85,
                ),
              ),
      ),
    );
  }
}
