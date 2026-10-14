import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:mbuniemail/core/api/api_client.dart';
import 'package:mbuniemail/core/config.dart';
import 'package:mbuniemail/core/storage/token_store.dart';

typedef Handler = FutureOr<FakeReply> Function(RequestOptions req);

class FakeReply {
  FakeReply(this.status, [this.body, this.headers = const {}]);
  final int status;
  final Object? body;
  final Map<String, List<String>> headers;
}

/// In-memory HTTP adapter: routes "METHOD path" to handlers and records every request.
class FakeAdapter implements HttpClientAdapter {
  final Map<String, Handler> routes = {};
  final List<RequestOptions> requests = [];

  void on(String method, String path, Handler h) => routes['$method $path'] = h;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    final path = options.uri.path.replaceFirst('/api/v1/', '');
    final handler = routes['${options.method} $path'];
    if (handler == null) {
      throw DioException.connectionError(
        requestOptions: options,
        reason: 'no route for ${options.method} $path',
      );
    }
    final r = await handler(options);
    final bytes = r.body is Uint8List
        ? r.body as Uint8List
        : utf8.encode(r.body == null ? '' : jsonEncode(r.body));
    return ResponseBody.fromBytes(
      bytes,
      r.status,
      headers: {
        Headers.contentTypeHeader: [
          r.body is Uint8List ? 'application/octet-stream' : 'application/json',
        ],
        ...r.headers,
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

final testConfig = AppConfig(
  apiBaseUrl: Uri.parse('https://mail-api.example.test'),
);

StoredSession session([
  String access = 'access-1',
  String refresh = 'refresh-1',
]) => StoredSession(
  accessToken: access,
  refreshToken: refresh,
  sessionId: 'family-1',
  userEmail: 'developer@mt.co.tz',
);

ApiClient client(FakeAdapter adapter, MemoryTokenStore store) =>
    ApiClient(config: testConfig, store: store, adapter: adapter);

Map<String, dynamic> problem(String code, int status) => {
  'code': code,
  'status': status,
  'title': code,
};
