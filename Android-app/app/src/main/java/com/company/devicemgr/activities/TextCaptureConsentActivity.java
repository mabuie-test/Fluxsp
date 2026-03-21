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
        boolean accessibilityEnabled = InAppTextCaptureManager.isAccessibilityServiceEnabled(this, KeyboardAccessibilityService.class);

        btnOpenAccessibility.setEnabled(consentGranted);
        if (consentGranted) {
            String consentDate = new java.util.Date(InAppTextCaptureManager.consentTs(this)).toString();
            tvStatus.setText("Consentimento registado em " + consentDate + "\n"
                    + InAppTextCaptureManager.buildAccessibilityStatus(this, KeyboardAccessibilityService.class));
        } else {
            tvStatus.setText("Status: pendente. Aceite o consentimento para ativar a função teclado e depois ligue o serviço de acessibilidade.");
        }

        btnAccept.setText(consentGranted && accessibilityEnabled ? "Consentimento ativo" : "Aceitar e ativar");
    }

    private void openAccessibilitySettings() {
        startActivity(new Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS));
        Toast.makeText(this, "Ative o serviço 'Teclado via acessibilidade' desta app.", Toast.LENGTH_LONG).show();
    }

    private void saveConsent(boolean accepted) {
        InAppTextCaptureManager.setConsent(this, accepted);
        if (accepted) {
            AppRuntime.ensureInAppTextCaptureStarted(this);
        }
        new Thread(() -> {
            try {
                String deviceId = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).getString("deviceId", "unknown");
                String token = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).getString("auth_token", null);
                JSONObject body = new JSONObject();
                body.put("accepted", accepted);
                body.put("consentTextVersion", InAppTextCaptureManager.consentVersion());
                HttpClient.postJson(ApiConfig.api("/api/devices/" + deviceId + "/in-app-text-consent"), body.toString(), token);
                runOnUiThread(() -> {
                    Toast.makeText(this, "Consentimento guardado.", Toast.LENGTH_SHORT).show();
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
