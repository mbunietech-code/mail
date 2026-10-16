import 'dart:io' show Platform;

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../features/mailbox/mail_repository.dart';
import 'api/api_client.dart';
import 'config.dart';
import 'storage/mail_cache.dart';
import 'storage/token_store.dart';

/// Composition root. Tests override [configProvider], [tokenStoreProvider] and
/// [apiClientProvider] with fakes.
final configProvider = Provider<AppConfig>(
  (ref) => AppConfig.fromEnvironment(),
);

final tokenStoreProvider = Provider<TokenStore>((ref) => SecureTokenStore());

final apiClientProvider = Provider<ApiClient>(
  (ref) => ApiClient(
    config: ref.watch(configProvider),
    store: ref.watch(tokenStoreProvider),
  ),
);

/// Encrypted offline copy of loaded mail. Widget tests cannot reach the keystore
/// or file-system plugins (their calls never complete), so they get memory.
final mailCacheProvider = Provider<MailCache>(
  (ref) => Platform.environment.containsKey('FLUTTER_TEST')
      ? MemoryMailCache()
      : EncryptedFileMailCache(),
);

final mailRepositoryProvider = Provider<MailRepository>(
  (ref) => MailRepository(
    ref.watch(apiClientProvider),
    ref.watch(mailCacheProvider),
  ),
);

/// Company email domains (public). Only these may sign in or be linked. An empty list
/// means "unknown" (offline): the server still enforces the rule.
final companyDomainsProvider = FutureProvider<List<String>>((ref) async {
  try {
    final j = await ref.watch(apiClientProvider).getPublic('auth/domains');
    return [for (final d in (j['domains'] as List? ?? const [])) '$d'];
  } catch (_) {
    return const [];
  }
});

bool emailInDomains(String email, List<String> domains) {
  final at = email.lastIndexOf('@');
  return domains.isEmpty ||
      (at > 0 &&
          domains.contains(email.substring(at + 1).trim().toLowerCase()));
}
