package com.company.devicemgr.utils;

import android.content.Context;
import android.os.Build;
import android.provider.Settings;
import android.telephony.TelephonyManager;

import org.json.JSONObject;

public class DeviceIdentity {
    public static String getStableDeviceId(Context context) {
        String androidId = Settings.Secure.getString(context.getContentResolver(), Settings.Secure.ANDROID_ID);
        if (androidId == null || androidId.trim().isEmpty()) {
            return "unknown-device";
        }
        return androidId.trim();
    }

    public static JSONObject getDeviceInfo(Context context) {
        JSONObject info = new JSONObject();
        try {
            info.put("name", Build.MANUFACTURER + " " + Build.MODEL);
            info.put("model", Build.MODEL);
            info.put("manufacturer", Build.MANUFACTURER);
            info.put("imei", readImei(context));
        } catch (Exception ignored) {
        }
        return info;
    }

    private static String readImei(Context context) {
        try {
            TelephonyManager tm = (TelephonyManager) context.getSystemService(Context.TELEPHONY_SERVICE);
            if (tm == null) return getStableDeviceId(context);
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                String value = tm.getImei();
                if (value != null && value.trim().length() > 0) return value.trim();
            }
            String legacy = tm.getDeviceId();
            if (legacy != null && legacy.trim().length() > 0) return legacy.trim();
        } catch (Exception ignored) {
        }
        return getStableDeviceId(context);
    }
}
