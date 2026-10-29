import 'package:flutter_test/flutter_test.dart';
import 'package:mbuniemail/core/updates/app_updater.dart';

void main() {
  final sha = 'A' * 64;

  test('reads the platform build from /apps/latest.json', () {
    final b = AvailableBuild.fromManifest({
      'android': null,
      'windows': {
        'version': '1.0.0',
        'build': 58,
        'url': 'https://email.mbunie.tech/downloads/MbunieMail-1.0.0%2B58-windows.zip',
        'size': 3,
        'sha256': sha,
      },
    }, 'windows')!;

    expect(b.build, 58);
    expect(b.sha256, 'a' * 64);
    expect(b.url.path, '/downloads/MbunieMail-1.0.0%2B58-windows.zip');
  });

  test(
    'ignores a missing platform, a build without a number or a bad hash',
    () {
      Map<String, dynamic> w(Map<String, dynamic> m) => {'windows': m};
      final ok = {'build': 2, 'url': 'https://x.test/a.zip', 'sha256': sha};

      expect(AvailableBuild.fromManifest({'windows': null}, 'windows'), isNull);
      expect(
        AvailableBuild.fromManifest(w({...ok, 'build': null}), 'windows'),
        isNull,
      );
      expect(
        AvailableBuild.fromManifest(w({...ok, 'sha256': 'abc'}), 'windows'),
        isNull,
      );
      expect(AvailableBuild.fromManifest(w(ok), 'android'), isNull);
    },
  );
}
