package com.company.devicemgr.activities;

import android.app.Activity;
import android.content.Intent;
import android.os.Bundle;
import android.provider.Settings;
import android.widget.Button;
import android.widget.CheckBox;
import android.widget.TextView;
import android.widget.Toast;

import com.company.devicemgr.services.KeyboardAccessibilityService;
import com.company.devicemgr.utils.ApiConfig;
import com.company.devicemgr.utils.AppRuntime;
import com.company.devicemgr.utils.HttpClient;
import com.company.devicemgr.utils.InAppTextCaptureManager;

import org.json.JSONObject;

public class TextCaptureConsentActivity extends Activity {
    private CheckBox cbConsent;
    private Button btnAccept;
    private Button btnSkip;
    private Button btnOpenAccessibility;
    private TextView tvStatus;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(com.company.devicemgr.R.layout.activity_text_capture_consent);

        cbConsent = findViewById(com.company.devicemgr.R.id.cbTextCaptureConsent);
        btnAccept = findViewById(com.company.devicemgr.R.id.btnAcceptTextCaptureConsent);
        btnSkip = findViewById(com.company.devicemgr.R.id.btnSkipTextCaptureConsent);
        btnOpenAccessibility = findViewById(com.company.devicemgr.R.id.btnOpenAccessibilitySettings);
        tvStatus = findViewById(com.company.devicemgr.R.id.tvTextCaptureConsentStatus);

        if (InAppTextCaptureManager.isConsentGranted(this)) {
            cbConsent.setChecked(true);
        }

        btnAccept.setOnClickListener(v -> {
            if (!cbConsent.isChecked()) {
                Toast.makeText(this, "Marque a caixa de consentimento para continuar.", Toast.LENGTH_SHORT).show();
                return;
            }
            saveConsent(true);
        });

        btnOpenAccessibility.setOnClickListener(v -> openAccessibilitySettings());

        btnSkip.setOnClickListener(v -> {
            startActivity(new Intent(this, MainPermissionsActivity.class));
            finish();
        });

        renderStatus();
    }

    @Override
    protected void onResume() {
        super.onResume();
        renderStatus();
    }

    private void renderStatus() {
        boolean consentGranted = InAppTextCaptureManager.isConsentGranted(this);
        btnOpenAccessibility.setEnabled(consentGranted);
        if (consentGranted) {
            String consentDate = new java.util.Date(InAppTextCaptureManager.consentTs(this)).toString();
            tvStatus.setText("Consentimento permanente registado nesta instalação em " + consentDate + "\n"
                    + "Modo: " + InAppTextCaptureManager.consentMode(this) + "\n"
                    + "Instalação: " + InAppTextCaptureManager.consentInstallInstanceId(this) + "\n"
                    + InAppTextCaptureManager.buildAccessibilityStatus(this, KeyboardAccessibilityService.class));
        } else {
            tvStatus.setText("Status: pendente. Aceite uma única vez o consentimento permanente desta instalação para ativar a função teclado e depois ligue o serviço de acessibilidade.");
        }

        btnAccept.setText(consentGranted ? "Consentimento permanente ativo" : "Aceitar permanentemente");
        btnAccept.setEnabled(!consentGranted);
        btnAccept.setAlpha(consentGranted ? 0.75f : 1f);
        cbConsent.setEnabled(!consentGranted);
    }

    private void openAccessibilitySettings() {
        startActivity(new Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS));
        Toast.makeText(this, "Ative o serviço 'Teclado via acessibilidade' desta app.", Toast.LENGTH_LONG).show();
    }

    private void saveConsent(boolean accepted) {
        boolean newlyGranted = accepted && InAppTextCaptureManager.grantPermanentConsent(this);
        if (accepted) {
            AppRuntime.ensureInAppTextCaptureStarted(this);
        }
        new Thread(() -> {
            try {
                String deviceId = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).getString("deviceId", "unknown");
                String token = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).getString("auth_token", null);
                JSONObject body = new JSONObject();
                body.put("accepted", accepted || InAppTextCaptureManager.isConsentGranted(this));
                body.put("consentTextVersion", InAppTextCaptureManager.consentVersion());
                body.put("consentMode", InAppTextCaptureManager.consentMode(this));
                body.put("installId", InAppTextCaptureManager.consentInstallInstanceId(this));
                body.put("isPermanent", true);
                HttpClient.postJson(ApiConfig.api("/api/devices/" + deviceId + "/in-app-text-consent"), body.toString(), token);
                runOnUiThread(() -> {
                    Toast.makeText(this, newlyGranted ? "Consentimento permanente guardado." : "Consentimento permanente já estava ativo.", Toast.LENGTH_SHORT).show();
                    renderStatus();
                    openAccessibilitySettings();
                });
            } catch (Exception e) {
                runOnUiThread(() -> {
                    renderStatus();
                    tvStatus.setText(tvStatus.getText() + "\nFalhou no backend: " + e.getMessage());
                });
            }
        }).start();
    }
}
