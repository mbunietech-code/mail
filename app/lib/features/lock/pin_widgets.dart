import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Row of PIN dots that fill as digits are typed and shake on a wrong PIN.
class PinDots extends StatefulWidget {
  const PinDots({
    super.key,
    required this.length,
    required this.filled,
    this.shakeSignal = 0,
  });

  final int length;
  final int filled;

  /// Increment to play the shake animation.
  final int shakeSignal;

  @override
  State<PinDots> createState() => _PinDotsState();
}

class _PinDotsState extends State<PinDots> with SingleTickerProviderStateMixin {
  late final _shake = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 420),
  );

  @override
  void didUpdateWidget(PinDots old) {
    super.didUpdateWidget(old);
    if (widget.shakeSignal != old.shakeSignal) {
      HapticFeedback.heavyImpact();
      _shake.forward(from: 0);
    }
  }

  @override
  void dispose() {
    _shake.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Semantics(
      label: '${widget.filled} of ${widget.length} digits entered',
      child: AnimatedBuilder(
        animation: _shake,
        builder: (_, child) => Transform.translate(
          // A damped sine: three quick swings that settle.
          offset: Offset(
            sin(_shake.value * pi * 6) * 14 * (1 - _shake.value),
            0,
          ),
          child: child,
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (var i = 0; i < widget.length; i++)
              AnimatedContainer(
                duration: const Duration(milliseconds: 160),
                curve: Curves.easeOutBack,
                margin: const EdgeInsets.symmetric(horizontal: 9),
                width: i < widget.filled ? 16 : 14,
                height: i < widget.filled ? 16 : 14,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: i < widget.filled
                      ? scheme.primary
                      : Colors.transparent,
                  border: Border.all(color: scheme.primary, width: 2),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// 3×4 number pad. [leading] sits left of 0 (e.g. the fingerprint button).
class PinPad extends StatelessWidget {
  const PinPad({
    super.key,
    required this.onDigit,
    required this.onBackspace,
    this.leading,
    this.enabled = true,
  });

  final ValueChanged<String> onDigit;
  final VoidCallback onBackspace;
  final Widget? leading;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    Widget key(String d) => _PadKey(
      label: d,
      onTap: enabled
          ? () {
              HapticFeedback.selectionClick();
              onDigit(d);
            }
          : null,
    );
    final rows = [
      ['1', '2', '3'],
      ['4', '5', '6'],
      ['7', '8', '9'],
    ];
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (final row in rows)
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [for (final d in row) key(d)],
          ),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            SizedBox.square(dimension: 84, child: Center(child: leading)),
            key('0'),
            SizedBox.square(
              dimension: 84,
              child: IconButton(
                tooltip: 'Delete digit',
                iconSize: 28,
                onPressed: enabled ? onBackspace : null,
                icon: const Icon(Icons.backspace_outlined),
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class _PadKey extends StatelessWidget {
  const _PadKey({required this.label, required this.onTap});
  final String label;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.all(6),
      child: Material(
        color: theme.colorScheme.surfaceContainerHighest.withValues(
          alpha: 0.55,
        ),
        shape: const CircleBorder(),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: SizedBox.square(
            dimension: 72,
            child: Center(
              child: Text(
                label,
                style: theme.textTheme.headlineSmall?.copyWith(
                  fontWeight: FontWeight.w500,
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
