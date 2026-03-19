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

    public static JSONObject getActiveSession(Context context) throws Exception {
        String url = ApiConfig.api("/api/support-sessions/device/" + currentDeviceId(context) + "/active");
        String res = HttpClient.getJson(url, currentToken(context));
        JSONObject root = new JSONObject(res != null ? res : "{}");
        return root.optJSONObject("session");
    }
}
