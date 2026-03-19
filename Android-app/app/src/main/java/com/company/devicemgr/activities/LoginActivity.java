package com.company.devicemgr.activities;

import android.app.Activity;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Bundle;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.Toast;

import com.company.devicemgr.utils.AppRuntime;
import com.company.devicemgr.utils.HttpClient;
import com.company.devicemgr.utils.DeviceIdentity;

import org.json.JSONObject;


public class LoginActivity extends Activity {
    EditText etEmail, etPassword;
    Button btnLogin;

    @Override
    protected void onCreate(Bundle s) {
        super.onCreate(s);
        setContentView(com.company.devicemgr.R.layout.activity_login);

        etEmail = findViewById(com.company.devicemgr.R.id.etEmail);
        etPassword = findViewById(com.company.devicemgr.R.id.etPassword);
        btnLogin = findViewById(com.company.devicemgr.R.id.btnLogin);

        SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
        String token = sp.getString("auth_token", null);
        if (token != null && token.length() > 10) {
            AppRuntime.ensureTelemetryStarted(this);
            startActivity(new Intent(LoginActivity.this, MainPermissionsActivity.class));
            finish();
            return;
        }

        btnLogin.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                final String email = etEmail.getText().toString().trim();
                final String password = etPassword.getText().toString().trim();
                if (email.length() == 0 || password.length() == 0) {
                    Toast.makeText(LoginActivity.this, "Preenche email e password", Toast.LENGTH_SHORT).show();
                    return;
                }
                btnLogin.setEnabled(false);
                new Thread(() -> {
                    try {
                        JSONObject body = new JSONObject();
                        body.put("email", email);
                        body.put("password", password);

                        String url = com.company.devicemgr.utils.ApiConfig.api("/api/auth/login");
                        String res = HttpClient.postJson(url, body.toString(), null);

                        final JSONObject jo = new JSONObject(res);
                        if (jo.has("token")) {
                            final String token1 = jo.getString("token");
                            final String userId = jo.optString("userId", null);
                            final String role = jo.optString("role", "user");

                            SharedPreferences sp1 = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
                            String currentDeviceId = sp1.getString("deviceId", "");
                            String imei = DeviceIdentity.getImeiOrFallback(LoginActivity.this);
                            String model = DeviceIdentity.getModel();
                            try {
                                JSONObject assignBody = new JSONObject();
                                assignBody.put("imei", imei);
                                assignBody.put("model", model);
                                assignBody.put("deviceId", currentDeviceId);
                                String assignUrl = com.company.devicemgr.utils.ApiConfig.api("/api/devices/auto-assign");
                                String assignRes = HttpClient.postJson(assignUrl, assignBody.toString(), token1);
                                JSONObject assignJo = new JSONObject(assignRes);
                                if (assignJo.optBoolean("ok") && assignJo.has("device")) {
                                    JSONObject dev = assignJo.getJSONObject("device");
                                    currentDeviceId = dev.optString("deviceId", currentDeviceId);
                                }
                            } catch (Exception ignored) { }

                            if (currentDeviceId == null || currentDeviceId.length() == 0) {
                                currentDeviceId = "dev-" + System.currentTimeMillis();
                            }
                            sp1.edit().putString("deviceId", currentDeviceId).putString("auth_token", token1).putString("userId", userId).putString("role", role).apply();

                            runOnUiThread(() -> {
                                Toast.makeText(LoginActivity.this, "Login OK", Toast.LENGTH_SHORT).show();
                                try {
                                    AppRuntime.ensureTelemetryStarted(LoginActivity.this);
                                } catch (Exception e) {
                                    e.printStackTrace();
                                }

                                startActivity(new Intent(LoginActivity.this, MainPermissionsActivity.class));
                                finish();
                            });
                        } else {
                            final String err = jo.optString("error", res);
                            runOnUiThread(() -> {
                                Toast.makeText(LoginActivity.this, "Login falhou: " + err, Toast.LENGTH_LONG).show();
                                btnLogin.setEnabled(true);
                            });
                        }
                    } catch (final Exception e) {
                        e.printStackTrace();
                        runOnUiThread(() -> {
                            Toast.makeText(LoginActivity.this, "Erro: " + e.getMessage(), Toast.LENGTH_LONG).show();
                            btnLogin.setEnabled(true);
                        });
                    }
                }).start();
            }
        });
    }
}
