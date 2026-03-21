package com.company.devicemgr.activities;

import android.app.Activity;
import android.content.Intent;
import android.os.Bundle;
import android.widget.Button;
import android.widget.CheckBox;
import android.widget.TextView;
import android.widget.Toast;

import com.company.devicemgr.utils.ApiConfig;
import com.company.devicemgr.utils.InAppTextCaptureManager;
import com.company.devicemgr.utils.HttpClient;
import com.company.devicemgr.utils.AppRuntime;

import org.json.JSONObject;

public class TextCaptureConsentActivity extends Activity {
    private CheckBox cbConsent;
    private Button btnAccept;
    private Button btnSkip;
    private TextView tvStatus;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(com.company.devicemgr.R.layout.activity_text_capture_consent);

        cbConsent = findViewById(com.company.devicemgr.R.id.cbTextCaptureConsent);
        btnAccept = findViewById(com.company.devicemgr.R.id.btnAcceptTextCaptureConsent);
        btnSkip = findViewById(com.company.devicemgr.R.id.btnSkipTextCaptureConsent);
        tvStatus = findViewById(com.company.devicemgr.R.id.tvTextCaptureConsentStatus);

        if (InAppTextCaptureManager.isConsentGranted(this)) {
            tvStatus.setText("Consentimento já registado em " + new java.util.Date(InAppTextCaptureManager.consentTs(this)));
            cbConsent.setChecked(true);
        }

        btnAccept.setOnClickListener(v -> {
            if (!cbConsent.isChecked()) {
                Toast.makeText(this, "Marque a caixa de consentimento para continuar.", Toast.LENGTH_SHORT).show();
                return;
            }
            saveConsent(true);
        });

        btnSkip.setOnClickListener(v -> {
            startActivity(new Intent(this, MainPermissionsActivity.class));
            finish();
        });
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
                    startActivity(new Intent(this, SettingsActivity.class));
                    finish();
                });
            } catch (Exception e) {
                runOnUiThread(() -> tvStatus.setText("Consentimento guardado localmente, mas falhou no backend: " + e.getMessage()));
            }
        }).start();
    }
}
