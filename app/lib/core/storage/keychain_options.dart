import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Keychain settings for every [FlutterSecureStorage] on iOS and macOS.
///
/// `first_unlock_this_device`: items never sync to iCloud Keychain and are never
/// restored from a backup or moved to a new device (the same as Android, where
/// backup and device transfer are off). They stay readable after the first unlock
/// so background work can run while the screen is locked.
const iosKeychain = IOSOptions(
  accessibility: KeychainAccessibility.first_unlock_this_device,
);

/// macOS also uses the data-protection keychain (the default), which needs the
/// `keychain-access-groups` entitlement in `macos/Runner/*.entitlements`.
const macKeychain = MacOsOptions(
  accessibility: KeychainAccessibility.first_unlock_this_device,
);
