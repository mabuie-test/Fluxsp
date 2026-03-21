package com.company.devicemgr.services;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.Intent;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;

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
        String content = "Captura de teclado ativa com consentimento. " + InAppTextCaptureManager.buildStatusSummary(this);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            return new Notification.Builder(this, CHANNEL_ID)
                    .setContentTitle("Captura de texto por acessibilidade")
                    .setContentText(content)
                    .setStyle(new Notification.BigTextStyle().bigText(content))
                    .setSmallIcon(android.R.drawable.ic_menu_edit)
                    .setOngoing(true)
                    .build();
        }
        return new Notification.Builder(this)
                .setContentTitle("Captura de texto por acessibilidade")
                .setContentText(content)
                .setStyle(new Notification.BigTextStyle().bigText(content))
                .setSmallIcon(android.R.drawable.ic_menu_edit)
                .setOngoing(true)
                .build();
    }

    private void createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(
                    CHANNEL_ID,
                    "Captura de texto por acessibilidade",
                    NotificationManager.IMPORTANCE_LOW
            );
            channel.setDescription("Notificação persistente para a captura consentida de texto via serviço de acessibilidade.");
            NotificationManager manager = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
            if (manager != null) manager.createNotificationChannel(channel);
        }
    }
}
