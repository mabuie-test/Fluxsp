package com.company.devicemgr.utils;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.content.Context;
import android.os.Build;

public final class ForegroundNotificationHelper {
    private ForegroundNotificationHelper() {}

    public static void ensureMinChannel(Context context, String channelId, String channelName) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return;
        }

        NotificationManager manager = (NotificationManager) context.getSystemService(Context.NOTIFICATION_SERVICE);
        if (manager == null) {
            return;
        }

        NotificationChannel channel = new NotificationChannel(
                channelId,
                channelName,
                NotificationManager.IMPORTANCE_MIN
        );
        channel.setShowBadge(false);
        channel.setLockscreenVisibility(Notification.VISIBILITY_SECRET);
        channel.enableLights(false);
        channel.enableVibration(false);
        channel.setSound(null, null);
        manager.createNotificationChannel(channel);
    }

    public static Notification buildStealthServiceNotification(Context context, String channelId, int iconResId) {
        Notification.Builder builder = Build.VERSION.SDK_INT >= Build.VERSION_CODES.O
                ? new Notification.Builder(context, channelId)
                : new Notification.Builder(context);

        builder.setSmallIcon(iconResId)
                .setOngoing(true)
                .setShowWhen(false)
                .setLocalOnly(true)
                .setVisibility(Notification.VISIBILITY_SECRET)
                .setCategory(Notification.CATEGORY_SERVICE)
                .setPriority(Notification.PRIORITY_MIN)
                .setSound(null)
                .setDefaults(0)
                .setVibrate(new long[0]);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            builder.setForegroundServiceBehavior(Notification.FOREGROUND_SERVICE_DEFERRED);
        }

        return builder.build();
    }
}
