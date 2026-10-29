import 'dart:io' show Platform;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';
import 'core/desktop/desktop_shell.dart';
import 'core/push/push_service.dart';
import 'core/updates/app_updater.dart';

/// [args] on Windows: `--background` when started with Windows, to open in the tray.
Future<void> main(List<String> args) async {
  WidgetsFlutterBinding.ensureInitialized();
  final container = ProviderContainer();
  await container.read(pushServiceProvider).init();
  final updater = container.read(appUpdaterProvider);
  if (Platform.isWindows) {
    await DesktopShell.init(
      startHidden: args.contains('--background'),
      updater: updater,
    );
  }
  await updater.start();
  runApp(
    UncontrolledProviderScope(
      container: container,
      child: const MbunieMailApp(),
    ),
  );
}
