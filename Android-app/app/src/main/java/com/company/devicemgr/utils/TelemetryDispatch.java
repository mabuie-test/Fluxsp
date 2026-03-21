package com.company.devicemgr.utils;

import android.content.Context;
import android.content.SharedPreferences;
import android.util.Log;

import org.json.JSONArray;
import org.json.JSONObject;

public final class TelemetryDispatch {
    private static final String TAG = "TelemetryDispatch";
    private static final String PREFS = "devicemgr_prefs";
    private static final String KEY_PENDING_EVENTS = "pending_events_queue";
    private static final int MAX_PENDING_EVENTS = 1000;

    private TelemetryDispatch() {}

    public static SharedPreferences prefs(Context context) {
        return context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    public static String currentToken(Context context) {
        return prefs(context).getString("auth_token", null);
    }

    public static String currentDeviceId(Context context) {
        return prefs(context).getString("deviceId", "unknown");
    }

    public static JSONObject eventBody(String type, JSONObject payload) throws Exception {
        JSONObject body = new JSONObject();
        body.put("type", type);
        body.put("payload", payload);
        return body;
    }

    public static boolean postEventNow(Context context, JSONObject body) {
        return postEventNow(currentDeviceId(context), currentToken(context), body);
    }

    public static boolean postEventNow(String deviceId, String token, JSONObject body) {
        String url = ApiConfig.api("/api/telemetry/" + deviceId);
        try {
            String res = HttpClient.postJson(url, body.toString(), token);
            return res != null && res.length() > 0;
        } catch (Exception e) {
            Log.e(TAG, "postEventNow err", e);
            return false;
        }
    }

    public static void enqueuePendingEvent(Context context, JSONObject body) {
        try {
            SharedPreferences sp = prefs(context);
            JSONArray arr = new JSONArray(sp.getString(KEY_PENDING_EVENTS, "[]"));
            JSONObject item = new JSONObject();
            item.put("body", body);
            item.put("queuedAt", System.currentTimeMillis());
            arr.put(item);

            JSONArray trimmed = new JSONArray();
            int start = Math.max(0, arr.length() - MAX_PENDING_EVENTS);
            for (int i = start; i < arr.length(); i++) {
                trimmed.put(arr.get(i));
            }
            sp.edit().putString(KEY_PENDING_EVENTS, trimmed.toString()).apply();
        } catch (Exception e) {
            Log.e(TAG, "enqueuePendingEvent err", e);
        }
    }

    public static int flushPendingEvents(Context context, int maxItems) {
        try {
            SharedPreferences sp = prefs(context);
            String token = currentToken(context);
            String deviceId = currentDeviceId(context);
            JSONArray arr = new JSONArray(sp.getString(KEY_PENDING_EVENTS, "[]"));
            if (arr.length() == 0) return 0;

            JSONArray remaining = new JSONArray();
            int attempts = 0;
            int delivered = 0;
            for (int i = 0; i < arr.length(); i++) {
                JSONObject row = arr.getJSONObject(i);
                JSONObject body = row.optJSONObject("body");
                if (body == null) continue;

                if (attempts >= maxItems) {
                    remaining.put(row);
                    continue;
                }
                attempts++;

                boolean ok = postEventNow(deviceId, token, body);
                if (ok) {
                    delivered++;
                } else {
                    remaining.put(row);
                }
            }
            sp.edit().putString(KEY_PENDING_EVENTS, remaining.toString()).apply();
            return delivered;
        } catch (Exception e) {
            Log.e(TAG, "flushPendingEvents err", e);
            return 0;
        }
    }

    public static boolean sendOrQueue(Context context, String type, JSONObject payload) {
        try {
            JSONObject body = eventBody(type, payload);
            boolean ok = postEventNow(context, body);
            if (!ok) enqueuePendingEvent(context, body);
            return ok;
        } catch (Exception e) {
            Log.e(TAG, "sendOrQueue err", e);
            return false;
        }
    }
}
