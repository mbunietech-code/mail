import 'package:flutter/foundation.dart';

/// Build-time configuration (`--dart-define`). Release builds must use HTTPS.
///
///   flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8080          (Android emulator → dev host)
///   flutter build apk --release --dart-define=API_BASE_URL=https://mail-api.mt.co.tz
class AppConfig {
  const AppConfig({required this.apiBaseUrl});

  final Uri apiBaseUrl;

  static const _rawBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8080',
  );

  static AppConfig fromEnvironment() {
    final uri = Uri.parse(_rawBaseUrl);
    if (kReleaseMode && uri.scheme != 'https') {
      throw StateError('Release builds require an https API_BASE_URL.');
    }
    return AppConfig(apiBaseUrl: uri);
  }

  Uri api(String path) => apiBaseUrl.resolve('/api/v1/$path');
}
