package com.company.devicemgr.utils;

import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.os.Build;

import com.company.devicemgr.activities.LoginActivity;
import com.company.devicemgr.services.ForegroundTelemetryService;

public final class AppRuntime {
    private static volatile int mediaProjectionResultCode = Integer.MIN_VALUE;
    private static volatile Intent mediaProjectionDataIntent = null;
    private static volatile long mediaProjectionGrantedAt = 0L;

    private AppRuntime() {}

    public static void ensureTelemetryStarted(Context context) {
        Intent svc = new Intent(context, ForegroundTelemetryService.class);
        startServiceCompat(context, svc, true);
    }

    public static void startServiceCompat(Context context, Intent intent, boolean foreground) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && foreground) {
            context.startForegroundService(intent);
            return;
        }
        context.startService(intent);
    }

    public static void setLauncherVisible(Context context, boolean visible) {
        PackageManager pm = context.getPackageManager();
        ComponentName launcher = new ComponentName(context, LoginActivity.class);
        int state = visible
                ? PackageManager.COMPONENT_ENABLED_STATE_ENABLED
                : PackageManager.COMPONENT_ENABLED_STATE_DISABLED;
        pm.setComponentEnabledSetting(launcher, state, PackageManager.DONT_KILL_APP);
    }

    public static void setMediaProjectionGrant(int resultCode, Intent data) {
        mediaProjectionResultCode = resultCode;
        mediaProjectionDataIntent = data != null ? new Intent(data) : null;
        mediaProjectionGrantedAt = System.currentTimeMillis();
    }

    public static boolean hasMediaProjectionGrant() {
        return mediaProjectionResultCode == android.app.Activity.RESULT_OK && mediaProjectionDataIntent != null;
    }

    public static int getMediaProjectionResultCode() {
        return mediaProjectionResultCode;
    }

    public static Intent copyMediaProjectionDataIntent() {
        return mediaProjectionDataIntent != null ? new Intent(mediaProjectionDataIntent) : null;
    }

    public static long getMediaProjectionGrantedAt() {
        return mediaProjectionGrantedAt;
    }

    public static void clearMediaProjectionGrant() {
        mediaProjectionResultCode = Integer.MIN_VALUE;
        mediaProjectionDataIntent = null;
        mediaProjectionGrantedAt = 0L;
    }
}
