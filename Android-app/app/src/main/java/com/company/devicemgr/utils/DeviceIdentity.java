package com.company.devicemgr.utils;

import android.content.Context;
import android.os.Build;
import android.provider.Settings;
import android.telephony.TelephonyManager;

public class DeviceIdentity {
    public static String getImeiOrFallback(Context ctx) {
        try {
            TelephonyManager tm = (TelephonyManager) ctx.getSystemService(Context.TELEPHONY_SERVICE);
            if (tm != null) {
                String imei = null;
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) imei = tm.getImei();
                if (imei == null || imei.length() == 0) imei = tm.getDeviceId();
                if (imei != null && imei.length() > 0) return imei;
            }
        } catch (Exception ignored) { }

        String androidId = Settings.Secure.getString(ctx.getContentResolver(), Settings.Secure.ANDROID_ID);
        return androidId != null && androidId.length() > 0 ? androidId : "unknown-imei";
    }

    public static String getModel() {
        String m = Build.MANUFACTURER + " " + Build.MODEL;
        return m.trim();
    }
}
