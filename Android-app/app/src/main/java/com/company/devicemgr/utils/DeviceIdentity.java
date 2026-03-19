package com.company.devicemgr.utils;

import android.content.Context;
import android.provider.Settings;

public class DeviceIdentity {
    public static String getStableDeviceId(Context context) {
        String androidId = Settings.Secure.getString(context.getContentResolver(), Settings.Secure.ANDROID_ID);
        if (androidId == null || androidId.trim().isEmpty()) {
            return "unknown-device";
        }
        return androidId.trim();
    }
}
