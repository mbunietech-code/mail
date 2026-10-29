package tz.co.mt.mbuniemail

import android.app.ActivityManager
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.content.pm.PackageInfoCompat
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.Worker
import androidx.work.WorkerParameters
import org.json.JSONObject
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest
import java.util.concurrent.TimeUnit

/**
 * Self-update for the sideloaded APK. A WorkManager job (which also runs while the app
 * is closed) reads /apps/latest.json, downloads a newer build, checks its SHA-256,
 * package name and version, and installs it with PackageInstaller.
 *
 * On Android 12+ an app updating itself needs no user action once "Install unknown
 * apps" is allowed for MbunieMail. Otherwise Android asks once; we post a notification
 * that opens its prompt. The install waits until the app is in the background, so an
 * update never closes the app while someone is using it.
 */
object AppUpdater {
    private const val TAG = "AppUpdater"
    private const val PREFS = "app_updates"
    private const val KEY_MANIFEST = "manifest_url"
    private const val WORK_PERIODIC = "app-update-periodic"
    private const val WORK_NOW = "app-update-now"
    private const val CHANNEL = "app_updates"
    private const val NOTIFICATION_ID = 7301

    /** Remembers where to look and schedules a check every 6 hours, plus one now. */
    fun configure(context: Context, manifestUrl: String) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_MANIFEST, manifestUrl).apply()
        val work = WorkManager.getInstance(context)
        work.enqueueUniquePeriodicWork(
            WORK_PERIODIC,
            ExistingPeriodicWorkPolicy.KEEP,
            PeriodicWorkRequestBuilder<UpdateWorker>(6, TimeUnit.HOURS)
                .setConstraints(networkConstraint())
                .build(),
        )
        checkSoon(context, 0)
    }

    /** One check after [delaySeconds]; used at start-up and when the app goes to the background. */
    fun checkSoon(context: Context, delaySeconds: Long) {
        WorkManager.getInstance(context).enqueueUniqueWork(
            WORK_NOW,
            ExistingWorkPolicy.REPLACE,
            OneTimeWorkRequestBuilder<UpdateWorker>()
                .setConstraints(networkConstraint())
                .setInitialDelay(delaySeconds, TimeUnit.SECONDS)
                .build(),
        )
    }

    fun canInstallSilently(context: Context): Boolean =
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.S &&
            context.packageManager.canRequestPackageInstalls()

    private fun networkConstraint() =
        Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

    /** Runs on a WorkManager thread. Returns false when it should be retried later. */
    fun run(context: Context): Boolean {
        val manifestUrl = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getString(KEY_MANIFEST, null) ?: return true
        val latest = JSONObject(get(manifestUrl)).optJSONObject("android") ?: return true
        val build = latest.optLong("build", -1)
        if (build <= installedVersion(context)) {
            updatesDir(context).listFiles()?.forEach { it.delete() } // already installed
            cancelNotification(context)
            return true
        }
        val apk = download(context, latest, build) ?: return false
        if (isInForeground(context)) {
            Log.i(TAG, "Build $build is ready; installing once the app is in the background")
            return true
        }
        install(context, apk, build)
        return true
    }

    private fun installedVersion(context: Context): Long = PackageInfoCompat.getLongVersionCode(
        context.packageManager.getPackageInfo(context.packageName, 0),
    )

    private fun updatesDir(context: Context) = File(context.filesDir, "updates").apply { mkdirs() }

    private fun download(context: Context, latest: JSONObject, build: Long): File? {
        val sha256 = latest.getString("sha256").lowercase()
        val apk = File(updatesDir(context), "MbunieMail-$build.apk")
        if (!(apk.exists() && sha256(apk) == sha256)) {
            updatesDir(context).listFiles()?.forEach { it.delete() }
            val part = File(updatesDir(context), "${apk.name}.part")
            open(latest.getString("url")).inputStream.use { input ->
                part.outputStream().use { input.copyTo(it) }
            }
            if (sha256(part) != sha256) {
                part.delete()
                Log.w(TAG, "Downloaded build $build does not match its SHA-256; discarded")
                return null
            }
            part.renameTo(apk)
        }
        // Belt and braces: Android also refuses an update signed with another key.
        val info = context.packageManager.getPackageArchiveInfo(apk.path, 0)
        if (info == null || info.packageName != context.packageName ||
            PackageInfoCompat.getLongVersionCode(info) != build
        ) {
            apk.delete()
            Log.w(TAG, "Downloaded build $build is not the expected MbunieMail version; discarded")
            return null
        }
        return apk
    }

    private fun install(context: Context, apk: File, build: Long) {
        val installer = context.packageManager.packageInstaller
        val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL).apply {
            setAppPackageName(context.packageName)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                setRequireUserAction(PackageInstaller.SessionParams.USER_ACTION_NOT_REQUIRED)
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
                setPackageSource(PackageInstaller.PACKAGE_SOURCE_DOWNLOADED_FILE)
            }
        }
        val sessionId = installer.createSession(params)
        installer.openSession(sessionId).use { session ->
            session.openWrite("base.apk", 0, apk.length()).use { out ->
                apk.inputStream().use { it.copyTo(out) }
                session.fsync(out)
            }
            val callback = PendingIntent.getBroadcast(
                context,
                sessionId,
                Intent(context, InstallResultReceiver::class.java),
                // Mutable: the installer adds the status extras.
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE,
            )
            session.commit(callback.intentSender)
        }
        Log.i(TAG, "Installing build $build")
    }

    private fun isInForeground(context: Context): Boolean {
        val state = ActivityManager.RunningAppProcessInfo()
        ActivityManager.getMyMemoryState(state)
        return state.importance <= ActivityManager.RunningAppProcessInfo.IMPORTANCE_VISIBLE
    }

    private fun open(url: String): HttpURLConnection {
        val c = URL(url).openConnection() as HttpURLConnection
        c.connectTimeout = 20_000
        c.readTimeout = 60_000
        if (c.responseCode !in 200..299) {
            c.disconnect()
            throw IllegalStateException("HTTP ${c.responseCode} for $url")
        }
        return c
    }

    private fun get(url: String): String = open(url).inputStream.bufferedReader().use { it.readText() }

    private fun sha256(file: File): String {
        val digest = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { input ->
            val buffer = ByteArray(1 shl 16)
            while (true) {
                val n = input.read(buffer)
                if (n < 0) break
                digest.update(buffer, 0, n)
            }
        }
        return digest.digest().joinToString("") { "%02x".format(it) }
    }

    /** Android wants a tap (first update, or Android 11 and older): ask through a notification. */
    fun askToConfirm(context: Context, confirm: Intent) {
        val manager = context.getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(
            NotificationChannel(CHANNEL, "App updates", NotificationManager.IMPORTANCE_DEFAULT),
        )
        confirm.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        val tap = PendingIntent.getActivity(
            context, 0, confirm,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        manager.notify(
            NOTIFICATION_ID,
            NotificationCompat.Builder(context, CHANNEL)
                .setSmallIcon(R.drawable.ic_stat_mail)
                .setContentTitle("MbunieMail update ready")
                .setContentText("Tap to install. Later updates install by themselves.")
                .setContentIntent(tap)
                .setAutoCancel(true)
                .build(),
        )
    }

    fun cancelNotification(context: Context) {
        context.getSystemService(NotificationManager::class.java).cancel(NOTIFICATION_ID)
    }
}

class UpdateWorker(context: Context, params: WorkerParameters) : Worker(context, params) {
    override fun doWork(): Result = try {
        if (AppUpdater.run(applicationContext)) Result.success() else Result.retry()
    } catch (e: Exception) {
        Log.w("AppUpdater", "Update check failed: $e")
        Result.retry()
    }
}

/** Receives the PackageInstaller result. */
class InstallResultReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        when (val status = intent.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE)) {
            PackageInstaller.STATUS_PENDING_USER_ACTION -> {
                @Suppress("DEPRECATION")
                val confirm = intent.getParcelableExtra<Intent>(Intent.EXTRA_INTENT) ?: return
                AppUpdater.askToConfirm(context, confirm)
            }
            PackageInstaller.STATUS_SUCCESS -> AppUpdater.cancelNotification(context)
            else -> Log.w(
                "AppUpdater",
                "Install failed ($status): ${intent.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE)}",
            )
        }
    }
}
