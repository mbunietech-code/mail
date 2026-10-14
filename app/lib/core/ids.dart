import 'dart:math';

/// Client-generated identifiers (Crockford base32, 26 chars like a ULID): used as
/// Idempotency-Key for sends and as draft/device ids. Time-prefixed for sortability,
/// random suffix from a CSPRNG.
String newClientId() {
  const alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
  final rnd = Random.secure();
  var time = DateTime.now().millisecondsSinceEpoch;
  final chars = List<String>.filled(26, '0');
  for (var i = 9; i >= 0; i--) {
    chars[i] = alphabet[time % 32];
    time ~/= 32;
  }
  for (var i = 10; i < 26; i++) {
    chars[i] = alphabet[rnd.nextInt(32)];
  }
  return chars.join();
}
