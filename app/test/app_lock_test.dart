import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mbuniemail/app.dart';
import 'package:mbuniemail/core/api/api_client.dart';
import 'package:mbuniemail/core/providers.dart';
import 'package:mbuniemail/core/storage/token_store.dart';
import 'package:mbuniemail/features/auth/auth_controller.dart';
import 'package:mbuniemail/features/lock/app_lock_controller.dart';
import 'package:mbuniemail/features/lock/lock_store.dart';

import 'fakes.dart';

class FakeBiometrics implements BiometricAuth {
  bool succeed = true;
  int prompts = 0;

  @override
  Future<bool> available() async => true;

  @override
  Future<bool> authenticate(String reason) async {
    prompts++;
    return succeed;
  }
}

/// App lock: PIN, fingerprint, attempt limits and background timeout.
void main() {
  late MemoryLockStore lockStore;
  late FakeBiometrics bio;

  ProviderContainer container() {
    final c = ProviderContainer(
      overrides: [
        lockStoreProvider.overrideWithValue(lockStore),
        pinHasherProvider.overrideWithValue(const PinHasher(iterations: 50)),
        biometricAuthProvider.overrideWithValue(bio),
      ],
    );
    addTearDown(c.dispose);
    return c;
  }

  Future<AppLockController> ready(ProviderContainer c) async {
    c.read(appLockProvider);
    await Future<void>.delayed(Duration.zero);
    return c.read(appLockProvider.notifier);
  }

  setUp(() {
    lockStore = MemoryLockStore();
    bio = FakeBiometrics();
  });

  test('the PIN is stored only as a salted hash and locks at start', () async {
    final first = container();
    await (await ready(first)).enable('2580');
    expect(lockStore.value!.toJson().toString(), isNot(contains('2580')));

    final next = container();
    final lock = await ready(next);
    expect(next.read(appLockProvider).locked, isTrue);
    expect(await lock.verify('2580'), isA<PinAccepted>());
    expect(next.read(appLockProvider).locked, isFalse);
  });

  test('wrong PINs: 4 tries, then a wait, then sign-out at 10', () async {
    final c = container();
    final lock = await ready(c);
    await lock.enable('2580');

    for (var left = 4; left >= 1; left--) {
      final r = await lock.verify('0000');
      expect((r as PinRejected).attemptsLeft, left);
    }
    expect(await lock.verify('0000'), isA<PinWait>());
    // Even the right PIN waits.
    expect(await lock.verify('2580'), isA<PinWait>());

    // Skip each wait (as if time passed) and keep failing until sign-out.
    PinResult r = const PinAccepted();
    while (r is! PinWiped) {
      lockStore.value = lockStore.value!.copyWith(clearLockedUntil: true);
      r = await (await ready(container())).verify('0000');
    }
    expect(lockStore.value, isNull);
  });

  test('locks again only after the chosen time in the background', () async {
    final c = container();
    final lock = await ready(c);
    await lock.enable('2580', timeoutSeconds: 60);

    lock.onBackground();
    lock.onForeground();
    expect(c.read(appLockProvider).locked, isFalse);

    await lock.setTimeout(0);
    lock.onBackground();
    lock.onForeground();
    expect(c.read(appLockProvider).locked, isTrue);
  });

  test('fingerprint unlocks only when turned on', () async {
    final c = container();
    final lock = await ready(c);
    await lock.enable('2580');
    lock.lockNow();

    expect(await lock.unlockWithBiometrics(), isFalse);
    expect(bio.prompts, 0);

    await lock.setBiometric(true);
    expect(await lock.unlockWithBiometrics(), isTrue);
    expect(c.read(appLockProvider).locked, isFalse);
  });

  testWidgets('the lock screen covers the app until the PIN is typed', (
    tester,
  ) async {
    final http = FakeAdapter()
      ..on('GET', 'me', (r) => FakeReply(200, {'email': 'dev@mt.co.tz'}))
      ..on('GET', 'accounts', (r) => FakeReply(200, {'data': []}))
      ..on('GET', 'sync/status', (r) => FakeReply(200, {'accounts': []}));
    final store = MemoryTokenStore()..value = session();
    tester.view.physicalSize = const Size(400, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);

    await tester.runAsync(() async {
      final c = container();
      await (await ready(c)).enable('2580');
    });

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          configProvider.overrideWithValue(testConfig),
          tokenStoreProvider.overrideWithValue(store),
          apiClientProvider.overrideWithValue(
            ApiClient(config: testConfig, store: store, adapter: http),
          ),
          deviceIdStoreProvider.overrideWithValue(MemoryDeviceIdStore()),
          lockStoreProvider.overrideWithValue(lockStore),
          pinHasherProvider.overrideWithValue(const PinHasher(iterations: 50)),
          biometricAuthProvider.overrideWithValue(bio),
        ],
        child: const MbunieMailApp(),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('Enter your PIN'), findsOneWidget);

    for (final d in ['2', '5', '8', '1']) {
      await tester.tap(find.text(d));
      await tester.pump();
    }
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 300)),
    );
    await tester.pumpAndSettle();
    expect(find.text('Wrong PIN. 4 tries left.'), findsOneWidget);

    for (final d in ['2', '5', '8', '0']) {
      await tester.tap(find.text(d));
      await tester.pump();
    }
    await tester.runAsync(
      () => Future<void>.delayed(const Duration(milliseconds: 300)),
    );
    await tester.pumpAndSettle();
    expect(find.text('Enter your PIN'), findsNothing);
  });
}
