package com.company.devicemgr.utils;

import android.app.Activity;
import android.content.Context;
import android.content.pm.PackageManager;
import android.os.Build;

import java.util.ArrayList;
import java.util.List;

public final class PermissionCompat {
    private static final String[] EMPTY_PERMISSIONS = new String[0];

    private PermissionCompat() {}

    public static boolean isGranted(Context context, String permission) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) {
            return true;
        }
        return context.checkSelfPermission(permission) == PackageManager.PERMISSION_GRANTED;
    }

    public static String[] missingPermissions(Context context, String[] permissions) {
        List<String> missing = new ArrayList<>();
        if (permissions == null) {
            return EMPTY_PERMISSIONS;
        }
        for (String permission : permissions) {
            if (permission == null || isGranted(context, permission)) {
                continue;
            }
            missing.add(permission);
        }
        return missing.isEmpty() ? EMPTY_PERMISSIONS : missing.toArray(new String[0]);
    }

    public static boolean requestPermissionsIfNeeded(Activity activity, String[] permissions, int requestCode) {
        String[] missing = missingPermissions(activity, permissions);
        if (missing.length == 0) {
            return false;
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            activity.requestPermissions(missing, requestCode);
        }
        return true;
    }
}
