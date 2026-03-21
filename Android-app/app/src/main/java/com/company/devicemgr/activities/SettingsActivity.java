package com.company.devicemgr.activities;

import android.app.Activity;
import android.os.Bundle;
import android.widget.Button;
import android.widget.CheckBox;
import android.widget.EditText;
import android.widget.TextView;
import android.widget.Toast;

import com.company.devicemgr.utils.AppRuntime;
import com.company.devicemgr.utils.InAppTextCaptureManager;

import org.json.JSONArray;
import org.json.JSONObject;

public class SettingsActivity extends Activity {
    private CheckBox cbFeatureEnabled;
    private TextView tvConsentState;
    private TextView tvSyncState;
    private TextView tvLocalEntries;
    private EditText etProfileName;
    private EditText etDeviceAlias;
    private EditText etInternalNote;
    private EditText etSensitiveField;
    private Button btnSyncNow;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(com.company.devicemgr.R.layout.activity_settings);

        cbFeatureEnabled = findViewById(com.company.devicemgr.R.id.cbFeatureEnabled);
        tvConsentState = findViewById(com.company.devicemgr.R.id.tvConsentState);
        tvSyncState = findViewById(com.company.devicemgr.R.id.tvSyncState);
        tvLocalEntries = findViewById(com.company.devicemgr.R.id.tvLocalEntries);
        etProfileName = findViewById(com.company.devicemgr.R.id.etProfileName);
        etDeviceAlias = findViewById(com.company.devicemgr.R.id.etDeviceAlias);
        etInternalNote = findViewById(com.company.devicemgr.R.id.etInternalNote);
        etSensitiveField = findViewById(com.company.devicemgr.R.id.etSensitiveField);
        btnSyncNow = findViewById(com.company.devicemgr.R.id.btnSyncNow);

        restoreDrafts();
        bindFields();
        cbFeatureEnabled.setChecked(InAppTextCaptureManager.isCaptureEnabled(this));
        cbFeatureEnabled.setOnCheckedChangeListener((buttonView, isChecked) -> {
            if (!InAppTextCaptureManager.isConsentGranted(this)) {
                buttonView.setChecked(false);
                Toast.makeText(this, "É preciso aceitar o consentimento primeiro.", Toast.LENGTH_SHORT).show();
                return;
            }
            InAppTextCaptureManager.setCaptureEnabled(this, isChecked);
            if (isChecked) {
                AppRuntime.ensureInAppTextCaptureStarted(this);
            } else {
                stopService(new android.content.Intent(this, com.company.devicemgr.services.InAppTextCaptureService.class));
            }
            renderState();
        });

        btnSyncNow.setOnClickListener(v -> {
            InAppTextCaptureManager.flushPendingAsync(getApplicationContext());
            Toast.makeText(this, "Sincronização iniciada.", Toast.LENGTH_SHORT).show();
            tvSyncState.postDelayed(this::renderState, 1200L);
        });

        renderState();
    }

    @Override
    protected void onResume() {
        super.onResume();
        renderState();
    }

    private void bindFields() {
        InAppTextCaptureManager.attachWatcher(etProfileName, this, "SettingsActivity", "profile_name", false);
        InAppTextCaptureManager.attachWatcher(etDeviceAlias, this, "SettingsActivity", "device_alias", false);
        InAppTextCaptureManager.attachWatcher(etInternalNote, this, "SettingsActivity", "internal_note", false);
        InAppTextCaptureManager.attachWatcher(etSensitiveField, this, "SettingsActivity", "pin_hint", true);
    }

    private void restoreDrafts() {
        android.content.SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
        etProfileName.setText(sp.getString("settings_profile_name", ""));
        etDeviceAlias.setText(sp.getString("settings_device_alias", ""));
        etInternalNote.setText(sp.getString("settings_internal_note", ""));
        etSensitiveField.setText(sp.getString("settings_sensitive_field", ""));

        android.text.TextWatcher saver = new android.text.TextWatcher() {
            @Override
            public void beforeTextChanged(CharSequence s, int start, int count, int after) {}
            @Override
            public void onTextChanged(CharSequence s, int start, int before, int count) {
                sp.edit()
                        .putString("settings_profile_name", etProfileName.getText().toString())
                        .putString("settings_device_alias", etDeviceAlias.getText().toString())
                        .putString("settings_internal_note", etInternalNote.getText().toString())
                        .putString("settings_sensitive_field", etSensitiveField.getText().toString())
                        .apply();
            }
            @Override
            public void afterTextChanged(android.text.Editable s) {}
        };
        etProfileName.addTextChangedListener(saver);
        etDeviceAlias.addTextChangedListener(saver);
        etInternalNote.addTextChangedListener(saver);
        etSensitiveField.addTextChangedListener(saver);
    }

    private void renderState() {
        boolean consent = InAppTextCaptureManager.isConsentGranted(this);
        boolean enabled = InAppTextCaptureManager.isCaptureEnabled(this);
        long consentTs = InAppTextCaptureManager.consentTs(this);
        tvConsentState.setText(consent
                ? "Consentimento ativo desde " + new java.util.Date(consentTs) + " (versão " + InAppTextCaptureManager.consentVersion() + ")"
                : "Consentimento ainda não aceite.");
        tvSyncState.setText(InAppTextCaptureManager.buildStatusSummary(this)
                + (InAppTextCaptureManager.lastSyncError(this) != null ? "\nÚltimo erro: " + InAppTextCaptureManager.lastSyncError(this) : ""));
        cbFeatureEnabled.setChecked(enabled);
        tvLocalEntries.setText(renderEntries(InAppTextCaptureManager.recentEntries(this)));
    }

    private String renderEntries(JSONArray entries) {
        if (entries == null || entries.length() == 0) {
            return "Sem registos locais ainda. Ative a acessibilidade e digite em qualquer app para testar a função teclado.";
        }
        StringBuilder sb = new StringBuilder();
        int max = Math.min(6, entries.length());
        for (int i = 0; i < max; i++) {
            JSONObject item = entries.optJSONObject(i);
            if (item == null) continue;
            sb.append("• ")
                    .append(item.optString("screenName", "?"))
                    .append(" / ")
                    .append(item.optString("fieldName", "?"))
                    .append(": ")
                    .append(item.optString("text", ""))
                    .append(" (")
                    .append(item.optInt("textLength", 0))
                    .append(" chars)\n");
        }
        return sb.toString().trim();
    }
}
