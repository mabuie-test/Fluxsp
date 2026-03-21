package com.company.devicemgr.utils;

import android.content.Context;
import android.content.SharedPreferences;
import android.text.Editable;
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

public final class InAppTextCaptureManager {
    private static final String TAG = "InAppTextCapture";
    private static final String PREFS = "devicemgr_prefs";
    private static final String KEY_CAPTURE_ENABLED = "in_app_text_capture_enabled";
    private static final String KEY_CAPTURE_CONSENT = "in_app_text_capture_consent";
    private static final String KEY_CAPTURE_CONSENT_TS = "in_app_text_capture_consent_ts";
    private static final String KEY_CAPTURE_CONSENT_VERSION = "in_app_text_capture_consent_version";
    private static final String KEY_PENDING_QUEUE = "in_app_text_capture_pending_queue";
    private static final String KEY_LOCAL_LOG = "in_app_text_capture_local_log";
    private static final String KEY_LAST_SYNC_AT = "in_app_text_capture_last_sync_at";
    private static final String KEY_LAST_SYNC_STATUS = "in_app_text_capture_last_sync_status";
    private static final String KEY_LAST_SYNC_ERROR = "in_app_text_capture_last_sync_error";
    private static final String KEY_LAST_VALUE_PREFIX = "in_app_text_capture_last_value_";
    private static final int MAX_LOCAL_ITEMS = 200;
    private static final String CONSENT_VERSION = "in-app-text-v1";

    private InAppTextCaptureManager() {}

    public static SharedPreferences prefs(Context context) {
        return context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    public static boolean isConsentGranted(Context context) {
        return prefs(context).getBoolean(KEY_CAPTURE_CONSENT, false);
    }

    public static boolean isCaptureEnabled(Context context) {
        SharedPreferences sp = prefs(context);
        return sp.getBoolean(KEY_CAPTURE_ENABLED, false) && sp.getBoolean(KEY_CAPTURE_CONSENT, false);
    }

    public static String consentVersion() {
        return CONSENT_VERSION;
    }

    public static void setConsent(Context context, boolean granted) {
        SharedPreferences.Editor editor = prefs(context).edit();
        editor.putBoolean(KEY_CAPTURE_CONSENT, granted);
        editor.putBoolean(KEY_CAPTURE_ENABLED, granted);
        if (granted) {
            editor.putLong(KEY_CAPTURE_CONSENT_TS, System.currentTimeMillis());
            editor.putString(KEY_CAPTURE_CONSENT_VERSION, CONSENT_VERSION);
        } else {
            editor.remove(KEY_CAPTURE_CONSENT_TS);
            editor.remove(KEY_CAPTURE_CONSENT_VERSION);
            editor.remove(KEY_PENDING_QUEUE);
        }
        editor.apply();
    }

    public static void setCaptureEnabled(Context context, boolean enabled) {
        prefs(context).edit().putBoolean(KEY_CAPTURE_ENABLED, enabled && isConsentGranted(context)).apply();
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
        boolean consent = sp.getBoolean(KEY_CAPTURE_CONSENT, false);
        boolean enabled = sp.getBoolean(KEY_CAPTURE_ENABLED, false);
        int pending = pendingCount(context);
        long lastSyncAt = sp.getLong(KEY_LAST_SYNC_AT, 0L);
        String status = sp.getString(KEY_LAST_SYNC_STATUS, "idle");
        return String.format(Locale.US,
                "Consentimento: %s | Ativo: %s | Pendentes: %d | Última sync: %s | Estado: %s",
                consent ? "sim" : "não",
                (consent && enabled) ? "sim" : "não",
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
            entry.put("captureScope", "own_app_only");
            entry.put("packageName", context.getPackageName());
        } catch (Exception e) {
            Log.e(TAG, "recordTextChange build err", e);
            return;
        }

        appendEntry(sp, KEY_PENDING_QUEUE, entry, MAX_LOCAL_ITEMS);
        appendEntry(sp, KEY_LOCAL_LOG, entry, MAX_LOCAL_ITEMS);
        flushPendingAsync(context.getApplicationContext());
    }

    public static void flushPendingAsync(Context context) {
        new Thread(() -> flushPending(context)).start();
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
        SharedPreferences.Editor editor = sp.edit()
                .putString(KEY_PENDING_QUEUE, newQueue.toString())
                .putLong(KEY_LAST_SYNC_AT, System.currentTimeMillis())
                .putString(KEY_LAST_SYNC_STATUS, syncStatus)
                .putString(KEY_LAST_SYNC_ERROR, syncError)
                .putLong("in_app_text_capture_last_sync_duration_ms", System.currentTimeMillis() - startedAt);
        editor.apply();
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
