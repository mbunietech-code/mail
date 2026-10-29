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
   flutter build apk --release --build-number=$(git rev-list --count HEAD) --dart-define=API_BASE_URL=https://email.mbunie.tech
   ```
   `--build-number` gives every build a higher `versionCode`. Without it each APK is `versionCode=1`, Android treats it as a reinstall, and launchers such as Infinix XOS drop the home-screen icon. Commit before building so the number goes up.

**Windows** (on a Windows 10/11 PC with Visual Studio 2022 "Desktop development with C++"):
```powershell
flutter build windows --release --dart-define=API_BASE_URL=https://mail-api.mt.co.tz
```
Then make the installer and the update package (IExpress, part of Windows):
```powershell
powershell -ExecutionPolicy Bypass -File windows\installer\build-installer.ps1
```
This writes `dist\MbunieMail-<version>+<build>.exe` (double-click installer for new PCs, served at `/apps/windows`) and `dist\MbunieMail-<version>+<build>-windows.zip` (what installed apps update from). Upload both to `backend/public/downloads`. The installer needs no admin rights. It installs to `%LOCALAPPDATA%\Programs\MbunieMail`, adds Start menu and desktop shortcuts, starts with Windows, and adds an Apps & features entry for uninstalling. `/Q` installs silently.

Sign the executables with the company code-signing certificate before distribution. Unsigned, Windows SmartScreen warns on first run.

## Automatic updates
Installed apps update themselves from `GET /apps/latest.json` on the server, which lists the newest file in `backend/public/downloads/` per platform. To release, upload with the build number in the name:
- Android: `MbunieMail-<version>+<build>.apk`, for example `MbunieMail-1.0.0+58.apk`
- Windows: `MbunieMail-<version>+<build>-windows.zip` from `build-installer.ps1` (the contents of `build\windows\x64\runner\Release`)

The app installs a build whose `<build>` is higher than its own, after checking its SHA-256. Always build with `--build-number` (see above). Release builds only; debug builds never update.
- **Android** (`AppUpdater.kt`): checks every 6 hours and on start in WorkManager, so it also runs while the app is closed. It downloads in the background and installs once the app leaves the screen. On Android 12+ that is silent after the user allows *Install unknown apps* for MbunieMail (Settings → App updates); before that, or on Android 8–11, a notification asks for one tap.
- **Windows** (`lib/core/updates`): checks every 6 hours, downloads to `%TEMP%`, and installs after the window has been hidden in the tray for 5 minutes, or when the user quits from the tray. The installer puts the app in `%LOCALAPPDATA%\Programs\MbunieMail`, which the user can write to; a copy in `Program Files` would not update.

## Windows tray
Closing the window hides MbunieMail in the notification area; it keeps syncing there. Click the tray icon to open it; right-click for *Start with Windows* (on by default, starts hidden in the tray) and *Quit MbunieMail*. Opening MbunieMail again brings back the running window instead of starting a second copy.

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
- Windows toasts (Android push via FCM is done).
- Deep links for password reset, invitation and Google OAuth completion. Reset links currently open in a browser.
- Gmail connection UI (feature-flagged off on the server) and optional screenshot blocking.
- Windows build, packaging and testing on a Windows PC.
