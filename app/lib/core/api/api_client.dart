import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';

import '../config.dart';
import '../errors.dart';
import '../ids.dart';
import '../storage/token_store.dart';

/// JSON client for the MbunieMail API.
///
/// * Access tokens (15 min) are attached automatically; on a 401 the refresh token is
///   exchanged **once** for all waiting requests (single flight), because the server
///   treats reuse of a rotated refresh token as theft and revokes the session.
/// * When refresh fails the local session is wiped and [onSessionLost] fires (FR-AUTH-002,
///   US-05: a revoked device loses access on its next call).
/// * Every error becomes an [AppFailure] built from the problem+json `code`.
class ApiClient {
  ApiClient({
    required this.config,
    required this.store,
    HttpClientAdapter? adapter,
    this.onSessionLost,
  }) {
    final options = BaseOptions(
      baseUrl: config.api('').toString(),
      connectTimeout: const Duration(seconds: 10),
      receiveTimeout: const Duration(seconds: 30),
      sendTimeout: const Duration(seconds: 60),
      headers: {'Accept': 'application/json'},
      validateStatus: (s) => s != null && s >= 200 && s < 300,
    );
    _dio = Dio(options);
    _refreshDio = Dio(options);
    if (adapter != null) {
      _dio.httpClientAdapter = adapter;
      _refreshDio.httpClientAdapter = adapter;
    }
  }

  final AppConfig config;
  final TokenStore store;
  void Function()? onSessionLost;

  late final Dio _dio;
  late final Dio _refreshDio;
  StoredSession? _session;
  Future<StoredSession?>? _refreshing;

  Future<StoredSession?> currentSession() async =>
      _session ??= await store.read();

  Future<void> setSession(StoredSession? session) async {
    _session = session;
    session == null ? await store.clear() : await store.write(session);
  }

  // ---- Public helpers -----------------------------------------------------------------

  Future<Map<String, dynamic>> getJson(
    String path, {
    Map<String, dynamic>? query,
  }) async => _asMap(await _send('GET', path, query: query));

  Future<Map<String, dynamic>> postJson(
    String path, [
    Object? body,
    Map<String, String>? headers,
  ]) async => _asMap(await _send('POST', path, body: body, headers: headers));

  Future<Map<String, dynamic>> patchJson(String path, Object body) async =>
      _asMap(await _send('PATCH', path, body: body));

  Future<Map<String, dynamic>> putJson(String path, Object body) async =>
      _asMap(await _send('PUT', path, body: body));

  Future<void> delete(String path, {Map<String, dynamic>? query}) async =>
      _send('DELETE', path, query: query);

  /// Unauthenticated (or explicitly-authenticated) call, e.g. login or MFA with a pending token.
  Future<Map<String, dynamic>> postPublic(
    String path,
    Object body, {
    String? bearer,
  }) async {
    try {
      final r = await _refreshDio.request<dynamic>(
        path,
        data: body,
        options: Options(
          method: 'POST',
          headers: {
            if (bearer != null) 'Authorization': 'Bearer $bearer',
            'X-Request-Id': newClientId(),
          },
        ),
      );
      return _asMap(r.data);
    } on DioException catch (e) {
      throw _failure(e);
    }
  }

  Future<Uint8List> getBytes(String path, {Map<String, dynamic>? query}) async {
    final data = await _send('GET', path, query: query, bytes: true);
    return data is Uint8List
        ? data
        : Uint8List.fromList((data as List).cast<int>());
  }

  /// Fetches an absolute URL with the session token — refused unless it is on the API's
  /// own origin, so the bearer token can never be sent to a third-party host.
  Future<Uint8List> getBytesFromApiUrl(Uri url) async {
    final base = config.apiBaseUrl;
    if (url.scheme != base.scheme ||
        url.host != base.host ||
        url.port != base.port ||
        !url.path.startsWith('/api/v1/')) {
      throw const AppFailure('FOREIGN_URL');
    }
    return getBytes(
      url.path.substring('/api/v1/'.length),
      query: url.queryParameters,
    );
  }

  Future<Map<String, dynamic>> upload(
    String path,
    String filename,
    Stream<List<int>> content,
    int length,
  ) async {
    final form = FormData.fromMap({
      'file': MultipartFile.fromStream(
        () => content,
        length,
        filename: filename,
      ),
    });
    return _asMap(await _send('POST', path, body: form));
  }

  // ---- Core -----------------------------------------------------------------------------

  Future<dynamic> _send(
    String method,
    String path, {
    Object? body,
    Map<String, dynamic>? query,
    Map<String, String>? headers,
    bool bytes = false,
  }) async {
    Future<Response<dynamic>> attempt(StoredSession? s) =>
        _dio.request<dynamic>(
          path,
          data: body,
          queryParameters: query,
          options: Options(
            method: method,
            responseType: bytes ? ResponseType.bytes : ResponseType.json,
            headers: {
              ...?headers,
              if (s != null) 'Authorization': 'Bearer ${s.accessToken}',
              'X-Request-Id': newClientId(),
            },
          ),
        );

    final session = await currentSession();
    if (session == null) throw const AppFailure('UNAUTHENTICATED', status: 401);

    try {
      return (await attempt(session)).data;
    } on DioException catch (e) {
      if (e.response?.statusCode != 401) throw _failure(e);
      final refreshed = await _refreshOnce(session);
      if (refreshed == null) {
        throw const AppFailure('SESSION_REVOKED', status: 401);
      }
      try {
        return (await attempt(refreshed)).data;
      } on DioException catch (e2) {
        throw _failure(e2);
      }
    }
  }

  /// Single-flight refresh: concurrent 401s wait for the same exchange.
  Future<StoredSession?> _refreshOnce(StoredSession stale) {
    if (_session != null && _session!.accessToken != stale.accessToken) {
      return Future.value(_session);
    }
    return _refreshing ??= _doRefresh(stale)
        .whenComplete(() => _refreshing = null);
  }

  Future<StoredSession?> _doRefresh(StoredSession stale) async {
    try {
      final r = await _refreshDio.post<dynamic>(
        'auth/refresh',
        data: {'refresh_token': stale.refreshToken},
      );
      final data = _asMap(r.data);
      final next = stale.copyWith(
        accessToken: data['access_token'] as String,
        refreshToken: data['refresh_token'] as String,
      );
      await setSession(next);
      return next;
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      if (status == 401 || status == 403) {
        await setSession(null); // wipe: tokens are dead on the server
        onSessionLost?.call();
        return null;
      }
      throw _failure(e);
    }
  }

  static Map<String, dynamic> _asMap(dynamic data) {
    if (data == null || data == '') return <String, dynamic>{};
    if (data is Map<String, dynamic>) return data;
    if (data is String) return jsonDecode(data) as Map<String, dynamic>;
    return {'data': data};
  }

  static AppFailure _failure(DioException e) {
    final r = e.response;
    if (r == null) {
      return const AppFailure('OFFLINE');
    }
    Map<String, dynamic> body = const {};
    try {
      body = _asMap(
        r.data is List<int> ? utf8.decode(r.data as List<int>) : r.data,
      );
    } catch (_) {}
    final retry = int.tryParse(r.headers.value('retry-after') ?? '');
    final errors = <String, List<String>>{};
    final raw = body['errors'];
    if (raw is Map) {
      raw.forEach(
        (k, v) => errors['$k'] = (v as List).map((x) => '$x').toList(),
      );
    }
    return AppFailure(
      (body['code'] as String?) ?? 'HTTP_${r.statusCode}',
      status: r.statusCode,
      detail: body['detail'] as String?,
      retryAfter: retry == null ? null : Duration(seconds: retry),
      fieldErrors: errors,
    );
  }
}
