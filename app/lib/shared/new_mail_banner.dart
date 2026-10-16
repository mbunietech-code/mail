import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/push/push_service.dart';
import 'brand_logo.dart';

/// Shows new mail that arrives while the app is open as a banner that drops in from
/// the top (logo pop, countdown bar). Tap opens the message; swipe up dismisses.
class NewMailBannerHost extends StatefulWidget {
  const NewMailBannerHost({
    super.key,
    required this.alerts,
    required this.onOpen,
    required this.child,
  });

  final Stream<MailAlert> alerts;
  final void Function(String messageId) onOpen;
  final Widget child;

  @override
  State<NewMailBannerHost> createState() => _NewMailBannerHostState();
}

class _NewMailBannerHostState extends State<NewMailBannerHost>
    with TickerProviderStateMixin {
  static const _visibleFor = Duration(seconds: 5);

  late final _entrance = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 520),
    reverseDuration: const Duration(milliseconds: 240),
  );
  late final _countdown =
      AnimationController(vsync: this, duration: _visibleFor)
        ..addStatusListener((s) {
          if (s == AnimationStatus.completed) _dismiss();
        });
  StreamSubscription<MailAlert>? _sub;
  MailAlert? _alert;

  @override
  void initState() {
    super.initState();
    _sub = widget.alerts.listen(_present);
  }

  @override
  void dispose() {
    _sub?.cancel();
    _entrance.dispose();
    _countdown.dispose();
    super.dispose();
  }

  void _present(MailAlert alert) {
    HapticFeedback.lightImpact();
    setState(() => _alert = alert);
    _entrance.forward(from: _entrance.value == 1 ? 0.6 : 0);
    _countdown.forward(from: 0);
  }

  Future<void> _dismiss() async {
    _countdown.stop();
    await _entrance.reverse();
    if (mounted) setState(() => _alert = null);
  }

  void _open() {
    final id = _alert?.messageId;
    _dismiss();
    if (id != null) widget.onOpen(id);
  }

  @override
  Widget build(BuildContext context) {
    final alert = _alert;
    return Stack(
      children: [
        widget.child,
        if (alert != null)
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: SafeArea(
              bottom: false,
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 480),
                  child: SlideTransition(
                    position:
                        Tween(
                          begin: const Offset(0, -1.3),
                          end: Offset.zero,
                        ).animate(
                          CurvedAnimation(
                            parent: _entrance,
                            curve: Curves.easeOutBack,
                            reverseCurve: Curves.easeInCubic,
                          ),
                        ),
                    child: FadeTransition(
                      opacity: CurvedAnimation(
                        parent: _entrance,
                        curve: const Interval(0, 0.5),
                      ),
                      child: _BannerCard(
                        alert: alert,
                        entrance: _entrance,
                        countdown: _countdown,
                        onTap: _open,
                        onDismiss: _dismiss,
                        onHold: (held) =>
                            held ? _countdown.stop() : _countdown.forward(),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

class _BannerCard extends StatelessWidget {
  const _BannerCard({
    required this.alert,
    required this.entrance,
    required this.countdown,
    required this.onTap,
    required this.onDismiss,
    required this.onHold,
  });

  final MailAlert alert;
  final Animation<double> entrance;
  final Animation<double> countdown;
  final VoidCallback onTap;
  final VoidCallback onDismiss;
  final ValueChanged<bool> onHold;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final title = alert.count > 1 ? alert.title : alert.sender;
    return Semantics(
      liveRegion: true,
      button: true,
      label: 'New mail. $title. ${alert.body}',
      child: GestureDetector(
        onTap: onTap,
        onTapDown: (_) => onHold(true),
        onTapCancel: () => onHold(false),
        onVerticalDragEnd: (d) {
          if ((d.primaryVelocity ?? 0) < -100) {
            onDismiss();
          } else {
            onHold(false);
          }
        },
        onVerticalDragStart: (_) => onHold(true),
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
          child: Material(
            color: scheme.surfaceContainerHigh,
            elevation: 10,
            shadowColor: scheme.shadow.withValues(alpha: 0.35),
            borderRadius: BorderRadius.circular(22),
            clipBehavior: Clip.antiAlias,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(14, 14, 14, 12),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      ScaleTransition(
                        scale: CurvedAnimation(
                          parent: entrance,
                          curve: const Interval(
                            0.3,
                            1,
                            curve: Curves.elasticOut,
                          ),
                        ),
                        child: const MbunieMailLogo(size: 44),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Text(
                                  'MbunieMail',
                                  style: theme.textTheme.labelMedium?.copyWith(
                                    color: scheme.primary,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                Text(
                                  '  ·  now',
                                  style: theme.textTheme.labelMedium?.copyWith(
                                    color: scheme.onSurfaceVariant,
                                  ),
                                ),
                                const Spacer(),
                                if (alert.count > 1)
                                  _CountBadge(count: alert.count),
                              ],
                            ),
                            const SizedBox(height: 2),
                            Text(
                              title,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.titleMedium?.copyWith(
                                fontWeight: FontWeight.w700,
                              ),
                            ),
                            Text(
                              alert.body,
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.bodyMedium?.copyWith(
                                color: scheme.onSurfaceVariant,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                AnimatedBuilder(
                  animation: countdown,
                  builder: (_, _) => LinearProgressIndicator(
                    value: 1 - countdown.value,
                    minHeight: 3,
                    backgroundColor: Colors.transparent,
                    color: scheme.primary.withValues(alpha: 0.7),
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

class _CountBadge extends StatelessWidget {
  const _CountBadge({required this.count});

  final int count;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: scheme.primary,
        borderRadius: BorderRadius.circular(10),
      ),
      child: Text(
        '$count',
        style: TextStyle(
          color: scheme.onPrimary,
          fontSize: 12,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}
