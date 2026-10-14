import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../features/mailbox/mail_repository.dart';
import 'api/api_client.dart';
import 'config.dart';
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

final mailRepositoryProvider = Provider<MailRepository>(
  (ref) => MailRepository(ref.watch(apiClientProvider)),
);
