import 'package:flutter/material.dart';

/// Small, shared motion vocabulary. Everything respects the platform
/// "reduce motion" setting (MediaQuery.disableAnimations).
class Motion {
  static const fast = Duration(milliseconds: 160);
  static const medium = Duration(milliseconds: 280);
  static const curve = Curves.easeOutCubic;

  static bool reduced(BuildContext context) =>
      MediaQuery.maybeDisableAnimationsOf(context) ?? false;
}

/// Fades and lifts a child into place; [index] staggers list entries (capped so long
/// lists never wait).
class EnterAnimation extends StatelessWidget {
  const EnterAnimation({super.key, required this.child, this.index = 0});
  final Widget child;
  final int index;

  @override
  Widget build(BuildContext context) {
    if (Motion.reduced(context)) return child;
    final delay = (index.clamp(0, 8)) * 0.06;
    return TweenAnimationBuilder<double>(
      tween: Tween(begin: 0, end: 1),
      duration: Motion.medium + Duration(milliseconds: (delay * 1000).round()),
      curve: Interval(delay / (delay + 0.28), 1, curve: Motion.curve),
      builder: (context, t, child) => Opacity(
        opacity: t,
        child: Transform.translate(
          offset: Offset(0, (1 - t) * 12),
          child: child,
        ),
      ),
      child: child,
    );
  }
}

/// Pulsing placeholder rows shown while a list loads (instead of a lone spinner).
class SkeletonList extends StatefulWidget {
  const SkeletonList({super.key, this.rows = 8, this.label = 'Loading'});
  final int rows;
  final String label;

  @override
  State<SkeletonList> createState() => _SkeletonListState();
}

class _SkeletonListState extends State<SkeletonList>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1100),
  )..repeat(reverse: true);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final base = Theme.of(context).colorScheme.surfaceContainerHigh;
    Widget bar(double w, double h) => Container(
      width: w,
      height: h,
      decoration: BoxDecoration(
        color: base,
        borderRadius: BorderRadius.circular(6),
      ),
    );
    return Semantics(
      label: widget.label,
      child: FadeTransition(
        opacity: Tween(begin: 0.45, end: 1.0).animate(_c),
        child: ListView.builder(
          physics: const NeverScrollableScrollPhysics(),
          itemCount: widget.rows,
          itemBuilder: (_, i) => Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            child: Row(
              children: [
                CircleAvatar(radius: 20, backgroundColor: base),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      bar(i.isEven ? 140 : 110, 12),
                      const SizedBox(height: 8),
                      bar(double.infinity, 10),
                      const SizedBox(height: 6),
                      bar(i.isEven ? 200 : 160, 10),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
