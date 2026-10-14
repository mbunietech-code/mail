import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:mbuniemail/core/errors.dart';
import 'package:mbuniemail/core/storage/token_store.dart';

import 'fakes.dart';

void main() {
  late FakeAdapter http;
  late MemoryTokenStore store;

  setUp(() {
    http = FakeAdapter();
    store = MemoryTokenStore()..value = session();
  });

  test('attaches the access token and a request id', () async {
    http.on(
      'GET',
      'me',
      (r) => FakeReply(200, {'email': 'developer@mt.co.tz'}),
    );

    await client(http, store).getJson('me');

    expect(http.requests.single.headers['Authorization'], 'Bearer access-1');
    expect(http.requests.single.headers['X-Request-Id'], isA<String>());
  });

  test(
    'concurrent 401s trigger exactly one refresh, then all requests succeed',
    () async {
      var refreshes = 0;
      http.on(
        'GET',
        'me',
        (r) => r.headers['Authorization'] == 'Bearer access-2'
            ? FakeReply(200, {'ok': true})
            : FakeReply(401, problem('UNAUTHENTICATED', 401)),
      );
      http.on('POST', 'auth/refresh', (r) async {
        refreshes++;
        await Future<void>.delayed(const Duration(milliseconds: 20));
        return FakeReply(200, {
          'access_token': 'access-2',
          'refresh_token': 'refresh-2',
        });
      });
      final api = client(http, store);

      final results = await Future.wait([
        api.getJson('me'),
        api.getJson('me'),
        api.getJson('me'),
      ]);

      expect(results, everyElement(containsPair('ok', true)));
      expect(
        refreshes,
        1,
        reason:
            'a second refresh with the rotated token would be treated as theft',
      );
      expect(store.value!.refreshToken, 'refresh-2');
    },
  );

  test('a rejected refresh wipes the local session and reports it', () async {
    var lost = false;
    http.on(
      'GET',
      'me',
      (r) => FakeReply(401, problem('UNAUTHENTICATED', 401)),
    );
    http.on(
      'POST',
      'auth/refresh',
      (r) => FakeReply(401, problem('SESSION_REVOKED', 401)),
    );
    final api = client(http, store)..onSessionLost = () => lost = true;

    await expectLater(
      api.getJson('me'),
      throwsA(
        isA<AppFailure>().having((f) => f.code, 'code', 'SESSION_REVOKED'),
      ),
    );

    expect(store.value, isNull, reason: 'tokens wiped from secure storage');
    expect(lost, isTrue);
  });

  test(
    'problem+json becomes an AppFailure with field errors and Retry-After',
    () async {
      http.on(
        'POST',
        'outbox/send',
        (r) => FakeReply(429, problem('RATE_LIMITED', 429), {
          'retry-after': ['30'],
        }),
      );
      http.on(
        'POST',
        'accounts',
        (r) => FakeReply(422, {
          ...problem('VALIDATION_FAILED', 422),
          'errors': {
            'email_address': ['The email address must be valid.'],
          },
        }),
      );
      final api = client(http, store);

      final limited = await api
          .postJson('outbox/send', {})
          .then<AppFailure?>(
            (_) => null,
            onError: (Object e) => e as AppFailure,
          );
      expect(limited!.code, 'RATE_LIMITED');
      expect(limited.retryAfter, const Duration(seconds: 30));

      final invalid = await api
          .postJson('accounts', {})
          .then<AppFailure?>(
            (_) => null,
            onError: (Object e) => e as AppFailure,
          );
      expect(invalid!.message, 'The email address must be valid.');
    },
  );

  test('network failures are reported as offline', () async {
    final failure = await client(http, store)
        .getJson('nowhere')
        .then<AppFailure?>((_) => null, onError: (Object e) => e as AppFailure);
    expect(failure!.isOffline, isTrue);
  });

  test(
    'the session token is never sent to another host (image proxy URLs)',
    () async {
      http.on(
        'GET',
        'image-proxy',
        (r) => FakeReply(200, Uint8List.fromList([1, 2, 3])),
      );
      final api = client(http, store);

      final bytes = await api.getBytesFromApiUrl(
        Uri.parse(
          'https://mail-api.example.test/api/v1/image-proxy?u=x&exp=1&sig=a',
        ),
      );
      expect(bytes, [1, 2, 3]);

      for (final foreign in [
        'https://evil.example/api/v1/image-proxy?u=x',
        'http://mail-api.example.test/api/v1/image-proxy',
        'https://mail-api.example.test:8443/api/v1/x',
        'https://mail-api.example.test/other',
      ]) {
        await expectLater(
          api.getBytesFromApiUrl(Uri.parse(foreign)),
          throwsA(isA<AppFailure>()),
          reason: foreign,
        );
      }
      expect(
        http.requests.where((r) => r.uri.host != 'mail-api.example.test'),
        isEmpty,
      );
    },
  );

  test('no request is attempted without a session', () async {
    store.value = null;
    await expectLater(
      client(http, store).getJson('me'),
      throwsA(
        isA<AppFailure>().having((f) => f.code, 'code', 'UNAUTHENTICATED'),
      ),
    );
    expect(http.requests, isEmpty);
  });
}
