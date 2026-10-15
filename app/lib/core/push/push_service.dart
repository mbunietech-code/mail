import 'dart:async';
import 'dart:io' show Platform;

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../errors.dart';
import '../providers.dart';

/// New-mail push on Android (FR-NOTIF-001). Firebase is optional: without a
/// google-services.json the app runs normally and simply gets no push.
///
/// The server sends only "New message / From `sender`" plus ids (FR-NOTIF-002);
/// content is fetched after the user opens the app.
class PushService {
  PushService(this._api);

  final ApiClient _api;
  final _local = FlutterLocalNotificationsPlugin();
  final _opened = StreamController<String>.broadcast();
  StreamSubscription<String>? _tokenRefresh;
  bool _ready = false;

  static const _channel = AndroidNotificationChannel(
    'new_mail',
    'New mail',
    description: 'A notification when new mail arrives',
    importance: Importance.high,
  );

  /// Message ids the user opened from a notification.
  Stream<String> get openedMessages => _opened.stream;

  Future<void> init() async {
    if (_ready || kIsWeb || !Platform.isAndroid) return;
    try {
      await Firebase.initializeApp();
    } catch (e) {
      debugPrint('Push disabled: Firebase is not configured ($e)');
      return;
    }
    _ready = true;

    await _local.initialize(
      settings: const InitializationSettings(
        android: AndroidInitializationSettings('@drawable/ic_stat_mail'),
      ),
      onDidReceiveNotificationResponse: (r) {
        final id = r.payload;
        if (id != null && id.isNotEmpty) _opened.add(id);
      },
    );
    await _local
        .resolvePlatformSpecificImplementation<
          AndroidFlutterLocalNotificationsPlugin
        >()
        ?.createNotificationChannel(_channel);

    // While the app is open the system does not show FCM notifications itself.
    FirebaseMessaging.onMessage.listen(_showForeground);
    FirebaseMessaging.onMessageOpenedApp.listen(_handleOpened);
    final initial = await FirebaseMessaging.instance.getInitialMessage();
    if (initial != null) _handleOpened(initial);
  }

  /// Sends this install's token to the server for the signed-in session.
  Future<void> register() async {
    if (!_ready) return;
    final messaging = FirebaseMessaging.instance;
    await messaging.requestPermission(); // Android 13+ asks; older versions allow
    // Registration tokens (not the newer FID register() flow) are what the backend's
    // FCM HTTP v1 "token" target expects.
    // ignore: deprecated_member_use
    final token = await messaging.getToken();
    if (token != null) await _send(token);
    // ignore: deprecated_member_use
    _tokenRefresh ??= messaging.onTokenRefresh.listen(_send);
  }

  /// The server clears the token on logout; also drop it here so a later sign-in
  /// gets a fresh one.
  Future<void> unregister() async {
    if (!_ready) return;
    await _tokenRefresh?.cancel();
    _tokenRefresh = null;
    // ignore: deprecated_member_use
    await FirebaseMessaging.instance.deleteToken();
  }

  Future<void> _send(String token) async {
    try {
      await _api.putJson('me/push-token', {'token': token});
    } on AppFailure catch (e) {
      debugPrint('Push token not registered: ${e.message}');
    }
  }

  void _handleOpened(RemoteMessage m) {
    final id = m.data['message_id'];
    if (id is String && id.isNotEmpty) _opened.add(id);
  }

  Future<void> _showForeground(RemoteMessage m) async {
    final n = m.notification;
    if (n == null) return;
    final accountId = '${m.data['account_id'] ?? ''}';
    await _local.show(
      id: accountId.hashCode, // one notification per account, replaced
      title: n.title,
      body: n.body,
      payload: '${m.data['message_id'] ?? ''}',
      notificationDetails: NotificationDetails(
        android: AndroidNotificationDetails(
          _channel.id,
          _channel.name,
          channelDescription: _channel.description,
          importance: Importance.high,
          priority: Priority.high,
          visibility: NotificationVisibility.private,
          icon: '@drawable/ic_stat_mail',
        ),
      ),
    );
  }
}

final pushServiceProvider = Provider<PushService>(
  (ref) => PushService(ref.watch(apiClientProvider)),
);
