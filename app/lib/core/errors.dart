/// A failure the UI can explain. Built from the API's problem+json `code`
/// (docs/API_SPECIFICATION.md §1.1) — never from raw server text.
class AppFailure implements Exception {
  const AppFailure(
    this.code, {
    this.status,
    this.detail,
    this.retryAfter,
    this.fieldErrors = const {},
  });

  final String code;
  final int? status;
  final String? detail;
  final Duration? retryAfter;
  final Map<String, List<String>> fieldErrors;

  bool get isAuthLost =>
      code == 'UNAUTHENTICATED' ||
      code == 'SESSION_REVOKED' ||
      code == 'ACCOUNT_DISABLED';
  bool get isOffline => code == 'OFFLINE';

  /// Short, actionable text for the user.
  String get message => switch (code) {
    'OFFLINE' => 'You are offline. Check your connection.',
    'INVALID_CREDENTIALS' => 'Incorrect email or password.',
    'INVALID_MFA_CODE' =>
      'That code is not valid. Check the time on your phone and try again.',
    'ACCOUNT_LOCKED' => 'Too many attempts. Wait a few minutes and try again.',
    'RATE_LIMITED' => 'Too many requests. Please wait a moment.',
    'ACCOUNT_DISABLED' =>
      'This account is not active. Contact your administrator.',
    'SESSION_REVOKED' =>
      'You were signed out for security. Please sign in again.',
    'UNAUTHENTICATED' => 'Please sign in again.',
    'REAUTH_REQUIRED' => 'Confirm your password to continue.',
    'PROVIDER_AUTH_FAILED' =>
      'The mail server rejected the password. Update it in Accounts.',
    'PROVIDER_UNREACHABLE' =>
      'The mail server is unreachable. MbunieMail will retry.',
    'PROVIDER_THROTTLED' => 'The mail provider is busy. Try again shortly.',
    'PROVIDER_TIMEOUT' => 'The mail server did not respond in time.',
    'PROVIDER_TLS_ERROR' => 'A secure connection to the mail server could not be verified. Contact IT.',
    'DOMAIN_NOT_ALLOWED' =>
      'Use your company email address. Other domains are not allowed.',
    'ACCOUNT_ALREADY_LINKED' => 'This mailbox is already linked.',
    'IDEMPOTENCY_MISMATCH' =>
      'This message was already submitted with different content.',
    'CONFIRMATION_REQUIRED' => 'This message may already have been delivered. Check Sent before sending again.',
    'ATTACHMENT_BLOCKED' => 'This file type is not allowed by company policy.',
    'PAYLOAD_TOO_LARGE' => 'The attachments are too large.',
    'VALIDATION_FAILED' =>
      fieldErrors.values.expand((e) => e).firstOrNull ??
          'Please check the form.',
    'NOT_FOUND' => 'This item no longer exists.',
    _ => detail ?? 'Something went wrong. Please try again.',
  };

  @override
  String toString() => 'AppFailure($code)';
}
