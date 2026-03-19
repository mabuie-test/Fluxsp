package com.company.devicemgr.services;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Intent;
import android.os.Build;
import android.os.IBinder;

import com.company.devicemgr.activities.MainPermissionsActivity;
import com.company.devicemgr.receivers.SupportSessionActionReceiver;
import com.company.devicemgr.utils.SupportSessionApi;

import org.json.JSONObject;

public class SupportSessionService extends Service {
    public static final String ACTION_SYNC = "com.company.devicemgr.action.SUPPORT_SYNC";
    public static final String ACTION_STOP_SESSION = "com.company.devicemgr.action.SUPPORT_STOP_SESSION";

    private static final String CHANNEL_ID = "devicemgr_support_session";
    private static final int NOTIFICATION_ID = 41;
    private volatile boolean running = false;

    @Override
    public void onCreate() {
        super.onCreate();
        createChannel();
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent != null ? intent.getAction() : null;
        if (ACTION_STOP_SESSION.equals(action)) {
            startForeground(NOTIFICATION_ID, buildNotification("A terminar sessão de suporte..."));
            new Thread(this::stopSessionFromServer).start();
            return START_NOT_STICKY;
        }

        if (!running) {
            running = true;
            startForeground(NOTIFICATION_ID, buildNotification("A verificar sessão de suporte..."));
            new Thread(this::syncLoop).start();
        }
        return START_STICKY;
    }

    private void syncLoop() {
        while (running) {
            try {
                JSONObject session = SupportSessionApi.getActiveSession(this);
                if (session == null || session.optString("sessionId", "").length() == 0) {
                    getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).edit().remove("active_support_session_json").apply();
                    stopForeground(true);
                    stopSelf();
                    running = false;
                    return;
                }

                getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).edit()
                        .putString("active_support_session_json", session.toString())
                        .apply();
                String text = "Sessão " + friendlyType(session.optString("requestType")) + " ativa até "
                        + session.optString("sessionExpiresAt", "-");
                NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
                if (nm != null) nm.notify(NOTIFICATION_ID, buildNotification(text));
            } catch (Exception ignored) {
            }

            try {
                Thread.sleep(15000L);
            } catch (InterruptedException ignored) {
                running = false;
                return;
            }
        }
    }

    private void stopSessionFromServer() {
        try {
            String raw = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).getString("active_support_session_json", null);
            if (raw != null && raw.length() > 0) {
                JSONObject session = new JSONObject(raw);
                String sessionId = session.optString("sessionId", "");
                if (sessionId.length() > 0) {
                    SupportSessionApi.stop(this, sessionId);
                }
            }
        } catch (Exception ignored) {
        } finally {
            getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).edit().remove("active_support_session_json").apply();
            running = false;
            stopForeground(true);
            stopSelf();
        }
    }

    private Notification buildNotification(String text) {
        Intent openIntent = new Intent(this, MainPermissionsActivity.class);
        PendingIntent openPi = PendingIntent.getActivity(
                this,
                0,
                openIntent,
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.M
                        ? PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
                        : PendingIntent.FLAG_UPDATE_CURRENT
        );

        Intent stopIntent = new Intent(this, SupportSessionActionReceiver.class);
        stopIntent.setAction(ACTION_STOP_SESSION);
        PendingIntent stopPi = PendingIntent.getBroadcast(
                this,
                1,
                stopIntent,
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.M
                        ? PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
                        : PendingIntent.FLAG_UPDATE_CURRENT
        );

        Notification.Builder builder = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                ? new Notification.Builder(this, CHANNEL_ID)
                : new Notification.Builder(this);

        builder.setContentTitle("DeviceMgr - sessão de suporte")
                .setContentText(text)
                .setSmallIcon(android.R.drawable.presence_video_online)
                .setContentIntent(openPi)
                .setOngoing(true)
                .addAction(android.R.drawable.ic_media_pause, "Parar", stopPi);

        return builder.build();
    }

    private String friendlyType(String type) {
        if ("ambient_audio".equals(type)) return "áudio";
        return "ecrã";
    }

    private void createChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(CHANNEL_ID, "Sessões de suporte", NotificationManager.IMPORTANCE_LOW);
            NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
            if (nm != null) nm.createNotificationChannel(channel);
        }
    }

    @Override
    public void onDestroy() {
        running = false;
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
