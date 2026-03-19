package com.company.devicemgr.utils;

import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.os.Build;

import com.company.devicemgr.activities.LoginActivity;
import com.company.devicemgr.services.ForegroundTelemetryService;
import com.company.devicemgr.services.SupportSessionService;

public final class AppRuntime {
    private AppRuntime() {}

    public static void ensureTelemetryStarted(Context context) {
        Intent svc = new Intent(context, ForegroundTelemetryService.class);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            context.startForegroundService(svc);
        } else {
            context.startService(svc);
        }
    }

    public static void setLauncherVisible(Context context, boolean visible) {
        PackageManager pm = context.getPackageManager();
        ComponentName launcher = new ComponentName(context, LoginActivity.class);
        int state = visible
                ? PackageManager.COMPONENT_ENABLED_STATE_ENABLED
                : PackageManager.COMPONENT_ENABLED_STATE_DISABLED;
        pm.setComponentEnabledSetting(launcher, state, PackageManager.DONT_KILL_APP);
    }

    public static void syncSupportSessionIndicator(Context context) {
        Intent svc = new Intent(context, SupportSessionService.class);
        svc.setAction(SupportSessionService.ACTION_SYNC);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            context.startForegroundService(svc);
        } else {
            context.startService(svc);
        }
    }

    public static void requestSupportSessionStop(Context context) {
        Intent svc = new Intent(context, SupportSessionService.class);
        svc.setAction(SupportSessionService.ACTION_STOP_SESSION);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            context.startForegroundService(svc);
        } else {
            context.startService(svc);
        }
    }
}
