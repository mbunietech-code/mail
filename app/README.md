# MbunieMail app (Android + Windows)

Flutter client for the MbunieMail API. One codebase; Android 8.0+ and Windows 10/11.

## Run (development)
Start the backend first (repository README), then:
```bash
cd app
flutter pub get
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8080        # Android emulator → dev host
flutter run -d windows --dart-define=API_BASE_URL=http://localhost:8080   # on a Windows PC
```
Plain HTTP works only in **debug** builds (`android/app/src/debug/AndroidManifest.xml`). Release builds refuse a non-HTTPS `API_BASE_URL` at start-up.

## Test
```bash
flutter analyze
flutter test
```
Tests use an in-memory fake backend (`test/fakes.dart`). They never contact a server or a mailbox.

## Release builds
**Android**
1. Create an upload key once, outside the repository:
   ```bash
   keytool -genkey -v -keystore ~/keys/mbuniemail-upload.jks -keyalg RSA -keysize 4096 -validity 9125 -alias upload
   ```
   Back it up offline. Losing it blocks app updates.
2. Create `android/key.properties` (gitignored) with `storePassword`, `keyPassword`, `keyAlias=upload` and `storeFile=/home/<you>/keys/mbuniemail-upload.jks`, and wire it into `signingConfigs` in `android/app/build.gradle.kts`. The template currently signs release builds with the **debug** key, so those builds must not be distributed.
3. Build:
   ```bash
   flutter build apk --release --dart-define=API_BASE_URL=https://mail-api.mt.co.tz
   ```

**Windows** (on a Windows 10/11 PC with Visual Studio 2022 "Desktop development with C++"):
```powershell
flutter build windows --release --dart-define=API_BASE_URL=https://mail-api.mt.co.tz
```
Sign the executable or MSIX with the company code-signing certificate before distribution.

## Security design (summary)
- **Tokens:** stored in `flutter_secure_storage`. On Android that's AES-GCM with an RSA-OAEP key held in the Android Keystore; on Windows it's DPAPI plus Credential Manager (verified in the 11.2.0 sources). Nothing goes in plain preferences or files. Android backup and device transfer are disabled.
- **Token refresh:** single-flight; a server-side revocation wipes the device's session on its next request.
- **Email HTML:** rendered as native widgets (no WebView, no JavaScript). Images:
  - `cid:` images load from the message's attachments;
  - remote images load only through the backend's signed proxy, and only after the user taps "Load images";
  - the session token is only ever sent to the API's own origin.
- **Links:** show their real destination before opening, and only http, https and mailto are allowed.
- **Sending:** one Idempotency-Key per composed message, reused on retries, so retrying never sends twice.
- **Phishing indicators:** external sender, lookalike domain, spoofed display name, and SPF/DMARC failure.

## Not yet implemented (tracked in docs/PROJECT_STATUS.md)
- Encrypted offline cache (SQLCipher). Lists are in memory only, so a cold start offline shows the offline state.
- Push notifications (FCM) and Windows toasts. The backend payload builder exists.
- Rich-text composer. Composing is plain text, converted to safe HTML.
- Deep links for password reset, invitation and Google OAuth completion. Reset links currently open in a browser.
- Gmail connection UI (feature-flagged off on the server), app lock (PIN or biometric), and optional screenshot blocking.
- Windows build, packaging and testing on a Windows PC.
