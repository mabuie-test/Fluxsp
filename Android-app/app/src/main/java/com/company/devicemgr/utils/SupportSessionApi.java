package com.company.devicemgr.utils;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONObject;

public final class SupportSessionApi {
    private static final String PREFS = "devicemgr_prefs";

    private SupportSessionApi() {}

    private static SharedPreferences prefs(Context context) {
        return context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    public static String currentToken(Context context) {
        return prefs(context).getString("auth_token", null);
    }

    public static String currentDeviceId(Context context) {
        return prefs(context).getString("deviceId", "unknown");
    }

    public static JSONObject getPendingSession(Context context) throws Exception {
        String url = ApiConfig.api("/api/support-sessions/device/" + currentDeviceId(context) + "/pending");
        String res = HttpClient.getJson(url, currentToken(context));
        JSONObject root = new JSONObject(res != null ? res : "{}");
        return root.optJSONObject("session");
    }

    public static JSONObject getActiveSession(Context context) throws Exception {
        String url = ApiConfig.api("/api/support-sessions/device/" + currentDeviceId(context) + "/active");
        String res = HttpClient.getJson(url, currentToken(context));
        JSONObject root = new JSONObject(res != null ? res : "{}");
        return root.optJSONObject("session");
    }

    public static JSONObject respond(Context context, String sessionId, String action, int sessionTtlSeconds) throws Exception {
        JSONObject body = new JSONObject();
        body.put("action", action);
        if (sessionTtlSeconds > 0) body.put("sessionTtlSeconds", sessionTtlSeconds);
        String url = ApiConfig.api("/api/support-sessions/" + sessionId + "/respond");
        String res = HttpClient.postJson(url, body.toString(), currentToken(context));
        JSONObject root = new JSONObject(res != null ? res : "{}");
        return root.optJSONObject("session");
    }

    public static JSONObject stop(Context context, String sessionId) throws Exception {
        String url = ApiConfig.api("/api/support-sessions/" + sessionId + "/stop");
        String res = HttpClient.postJson(url, new JSONObject().toString(), currentToken(context));
        JSONObject root = new JSONObject(res != null ? res : "{}");
        return root.optJSONObject("session");
    }

    public static void logEvent(Context context, String sessionId, String eventType, JSONObject metadata) throws Exception {
        JSONObject body = new JSONObject();
        body.put("eventType", eventType);
        body.put("metadata", metadata != null ? metadata : new JSONObject());
        String url = ApiConfig.api("/api/support-sessions/" + sessionId + "/event");
        HttpClient.postJson(url, body.toString(), currentToken(context));
    }
}
