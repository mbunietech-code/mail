package tz.co.mt.mbuniemail

import android.content.Intent
import android.net.Uri
import android.provider.Settings
import io.flutter.embedding.android.FlutterFragmentActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

// FragmentActivity: local_auth's fingerprint prompt needs it (app lock).
class MainActivity : FlutterFragmentActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "mbuniemail/updates")
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "configure" -> {
                        AppUpdater.configure(this, call.argument<String>("manifestUrl")!!)
                        result.success(null)
                    }
                    "checkNow" -> {
                        AppUpdater.checkSoon(this, 0)
                        result.success(null)
                    }
                    "canInstallSilently" -> result.success(AppUpdater.canInstallSilently(this))
                    "openInstallSettings" -> {
                        startActivity(
                            Intent(
                                Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                                Uri.parse("package:$packageName"),
                            ),
                        )
                        result.success(null)
                    }
                    else -> result.notImplemented()
                }
            }
    }

    // A downloaded update installs once the app leaves the screen.
    override fun onStop() {
        super.onStop()
        if (!isChangingConfigurations) AppUpdater.checkSoon(this, 15)
    }
}
