import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app.dart';
import 'core/push/push_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final container = ProviderContainer();
  await container.read(pushServiceProvider).init();
  runApp(
    UncontrolledProviderScope(
      container: container,
      child: const MbunieMailApp(),
    ),
  );
}
