import 'dart:io';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mbuniemail/core/api/api_client.dart';
import 'package:mbuniemail/core/errors.dart';
import 'package:mbuniemail/core/storage/mail_cache.dart';
import 'package:mbuniemail/core/storage/token_store.dart';
import 'package:mbuniemail/features/mailbox/mail_repository.dart';

import 'fakes.dart';

/// Offline mail: encrypted at rest, served only when the server is unreachable.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  late Directory tmp;

  EncryptedFileMailCache cache({int maxEntries = 300}) =>
      EncryptedFileMailCache(baseDir: () async => tmp, maxEntries: maxEntries);

  setUp(() async {
    FlutterSecureStorage.setMockInitialValues({});
    tmp = await Directory.systemTemp.createTemp('mbunie-cache-');
  });
  tearDown(() => tmp.delete(recursive: true));

  Future<List<File>> files() async => (await Directory(
    '${tmp.path}/mail_cache',
  ).list().toList()).whereType<File>().toList();

  test('values round-trip but are encrypted on disk', () async {
    final c = cache();
    await c.write('message:M1', {'subject': 'Salary revisions 2027'});

    expect(await c.read('message:M1'), {'subject': 'Salary revisions 2027'});
    final raw = await (await files()).single.readAsBytes();
    expect(String.fromCharCodes(raw), isNot(contains('Salary')));
    expect((await files()).single.path, isNot(contains('M1')));
  });

  test('a file swapped in for another key is rejected', () async {
    final c = cache();
    await c.write('a', {'v': 1});
    await c.write('b', {'v': 2});
    final all = await files();
    await all[1].writeAsBytes(await all[0].readAsBytes());

    final results = [await c.read('a'), await c.read('b')];
    expect(results.where((r) => r == null), hasLength(1));
  });

  test('sign-out clear removes the files and the key', () async {
    final c = cache();
    await c.write('a', {'v': 1});
    await c.clear();

    expect(Directory('${tmp.path}/mail_cache').existsSync(), isFalse);
    expect(await cache().read('a'), isNull);
  });

  test('old entries beyond the limit are pruned on start', () async {
    final c = cache();
    for (var i = 0; i < 5; i++) {
      await c.write('k$i', {'i': i});
    }
    final fresh = cache(maxEntries: 2);
    await fresh.read('k0'); // first access prunes
    await Future<void>.delayed(const Duration(milliseconds: 200));
    expect(await files(), hasLength(2));
  });

  group('repository', () {
    late FakeAdapter http;
    late MemoryMailCache memory;
    late MailRepository repo;
    var online = true;

    setUp(() {
      http = FakeAdapter();
      memory = MemoryMailCache();
      final store = MemoryTokenStore()..value = session();
      repo = MailRepository(
        ApiClient(config: testConfig, store: store, adapter: http),
        memory,
      );
      online = true;
      http.on('GET', 'accounts/A1/folders', (r) {
        if (!online) throw StateError('offline'); // -> connection error
        return FakeReply(200, {
          'data': [
            {'id': 'F1', 'name': 'INBOX', 'role': 'inbox'},
          ],
        });
      });
      http.on(
        'GET',
        'accounts',
        (r) => FakeReply(500, {'code': 'INTERNAL_ERROR'}),
      );
    });

    test('offline reads fall back to the last copy', () async {
      expect((await repo.folders('A1')).single.id, 'F1');
      online = false;

      expect((await repo.folders('A1')).single.id, 'F1');
    });

    test(
      'right after going offline, saved copies come without waiting',
      () async {
        await repo.folders('A1');
        online = false;
        await repo.folders('A1'); // finds out it is offline
        final calls = http.requests.length;

        expect((await repo.folders('A1')).single.id, 'F1');
        expect(http.requests.length, calls, reason: 'no network attempt');
      },
    );

    test('server errors are not hidden behind the cache', () async {
      memory.values['accounts'] = {'data': []};

      await expectLater(repo.accounts(), throwsA(isA<AppFailure>()));
    });
  });
}
