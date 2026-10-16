import 'dart:async';
import 'dart:convert' show HtmlEscape;
import 'dart:io' show Platform;

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart' show Color;
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../shared/brand_logo.dart';
import '../api/api_client.dart';
import '../errors.dart';
import '../providers.dart';

/// One new-mail push (FR-NOTIF-002): the sender, and the subject only if the user
/// opted in. Never the body; content is fetched after the user opens the app.
class MailAlert {
  const MailAlert({
    required this.accountId,
    required this.messageId,
    required this.title,
    required this.body,
    required this.sender,
    required this.receivedAt,
    this.subject,
    this.count = 1,
  });

  final String accountId;
  final String messageId;
  final String title;
  final String body;
  final String sender;
  final String? subject;
  final int count;
  final DateTime receivedAt;

  static MailAlert? fromData(Map<String, dynamic> d) {
    final messageId = '${d['message_id'] ?? ''}';
    if (messageId.isEmpty) return null;
    final ms = int.tryParse('${d['received_at'] ?? ''}');
    return MailAlert(
      accountId: '${d['account_id'] ?? ''}',
      messageId: messageId,
      title: '${d['title'] ?? 'New message'}',
      body: '${d['body'] ?? ''}',
      sender: '${d['sender'] ?? d['title'] ?? ''}',
      subject: d['subject'] == null ? null : '${d['subject']}',
      count: int.tryParse('${d['count'] ?? ''}') ?? 1,
      receivedAt: ms == null
          ? DateTime.now()
          : DateTime.fromMillisecondsSinceEpoch(ms),
    );
  }
}

/// Draws new-mail notifications: the MbunieMail logo, brand colour, sender in bold,
/// private on the lock screen. Used from the app and from the background isolate.
class MailNotifications {
  static const brand = Color(0xFF2B2F86);

  static final channel = AndroidNotificationChannel(
    'mail_alerts',
    'New mail',
    description: 'A notification when new mail arrives',
    importance: Importance.high,
    vibrationPattern: Int64List.fromList([0, 90, 70, 140]),
    enableLights: true,
    ledColor: brand,
  );

  final plugin = FlutterLocalNotificationsPlugin();
  Uint8List? _logo;

  Future<void> init({void Function(String messageId)? onOpen}) async {
    await plugin.initialize(
      settings: const InitializationSettings(
        android: AndroidInitializationSettings('@drawable/ic_stat_mail'),
      ),
      onDidReceiveNotificationResponse: (r) {
        final id = r.payload;
        if (id != null && id.isNotEmpty) onOpen?.call(id);
      },
    );
    final android = plugin
        .resolvePlatformSpecificImplementation<
          AndroidFlutterLocalNotificationsPlugin
        >();
    await android?.createNotificationChannel(channel);
    // Replaced by mail_alerts (a channel's sound and vibration cannot change).
    await android?.deleteNotificationChannel(channelId: 'new_mail');
  }

  Future<void> show(MailAlert a) async {
    _logo ??= await _renderLogo();
    const escape = HtmlEscape();
    final title = a.count > 1 ? a.title : a.sender;
    await plugin.show(
      id: a.accountId.hashCode, // one notification per account, replaced
      title: title,
      body: a.body,
      payload: a.messageId,
      notificationDetails: NotificationDetails(
        android: AndroidNotificationDetails(
          channel.id,
          channel.name,
          channelDescription: channel.description,
          importance: Importance.high,
          priority: Priority.high,
          category: AndroidNotificationCategory.email,
          visibility: NotificationVisibility.private,
          icon: '@drawable/ic_stat_mail',
          color: brand,
          largeIcon: _logo == null ? null : ByteArrayAndroidBitmap(_logo!),
          styleInformation: BigTextStyleInformation(
            escape.convert(a.body),
            htmlFormatBigText: true,
            contentTitle: '<b>${escape.convert(title)}</b>',
            htmlFormatContentTitle: true,
          ),
          subText: a.count > 1 ? '${a.count} unread' : 'Inbox',
          when: a.receivedAt.millisecondsSinceEpoch,
          number: a.count,
          ticker: 'New mail from ${a.sender}',
          vibrationPattern: channel.vibrationPattern,
          enableLights: true,
          ledColor: brand,
          ledOnMs: 600,
          ledOffMs: 2400,
        ),
      ),
    );
  }

  static Future<Uint8List?> _renderLogo() async {
    try {
      return await MbunieMailLogo.png(192).timeout(const Duration(seconds: 2));
    } catch (e) {
      debugPrint('Notification logo unavailable: $e');
      return null;
    }
  }
}

/// Runs in a background isolate when a push arrives while the app is not open.
@pragma('vm:entry-point')
Future<void> firebaseBackgroundMessage(RemoteMessage message) async {
  final alert = MailAlert.fromData(message.data);
  if (alert == null) return;
  await Firebase.initializeApp();
  final notifications = MailNotifications();
  await notifications.init();
  await notifications.show(alert);
}

/// New-mail push on Android (FR-NOTIF-001). Firebase is optional: without a
/// google-services.json the app runs normally and simply gets no push.
///
/// The server sends data-only messages; the app draws them (see [MailNotifications]),
/// or shows the in-app banner when it is open.
class PushService {
  PushService(this._api);

  final ApiClient _api;
  final _notifications = MailNotifications();
  // Not broadcast: a message opened from a notification at cold start is kept
  // until the app starts listening.
  final _opened = StreamController<String>();
  final _incoming = StreamController<MailAlert>.broadcast();
  StreamSubscription<String>? _tokenRefresh;
  bool _ready = false;

  /// Message ids the user opened from a notification.
  Stream<String> get openedMessages => _opened.stream;

  /// New mail that arrived while the app is in the foreground.
  Stream<MailAlert> get incoming => _incoming.stream;

  Future<void> init() async {
    if (_ready || kIsWeb || !Platform.isAndroid) return;
    try {
      await Firebase.initializeApp();
    } catch (e) {
      debugPrint('Push disabled: Firebase is not configured ($e)');
      return;
    }
    _ready = true;
    FirebaseMessaging.onBackgroundMessage(firebaseBackgroundMessage);

    await _notifications.init(onOpen: _opened.add);
    final launch = await _notifications.plugin
        .getNotificationAppLaunchDetails();
    final launchId = launch?.notificationResponse?.payload;
    if ((launch?.didNotificationLaunchApp ?? false) &&
        launchId != null &&
        launchId.isNotEmpty) {
      _opened.add(launchId);
    }

    FirebaseMessaging.onMessage.listen(_onForeground);
  }

  /// Sends this install's token to the server for the signed-in session.
  Future<void> register() async {
    if (!_ready) return;
    final messaging = FirebaseMessaging.instance;
    await messaging
        .requestPermission(); // Android 13+ asks; older versions allow
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

  void _onForeground(RemoteMessage m) {
    final alert = MailAlert.fromData(m.data);
    if (alert == null) return;
    // The in-app banner shows it; fall back to a notification if nothing listens.
    if (_incoming.hasListener) {
      _incoming.add(alert);
    } else {
      _notifications.show(alert);
    }
  }
}

final pushServiceProvider = Provider<PushService>(
  (ref) => PushService(ref.watch(apiClientProvider)),
);
