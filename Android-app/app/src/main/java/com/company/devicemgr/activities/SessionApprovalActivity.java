package com.company.devicemgr.activities;

import android.app.Activity;
import android.app.AlertDialog;
import android.os.Bundle;
import android.widget.Button;
import android.widget.TextView;
import android.widget.Toast;

import com.company.devicemgr.utils.AppRuntime;
import com.company.devicemgr.utils.SupportSessionApi;

public class SessionApprovalActivity extends Activity {
    public static final String EXTRA_SESSION_ID = "session_id";
    public static final String EXTRA_REQUEST_TYPE = "request_type";
    public static final String EXTRA_REQUESTED_AT = "requested_at";
    public static final String EXTRA_DEADLINE_AT = "deadline_at";

    private TextView tvMessage;
    private Button btnApprove;
    private Button btnReject;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(com.company.devicemgr.R.layout.activity_session_approval);

        tvMessage = findViewById(com.company.devicemgr.R.id.tvSessionMessage);
        btnApprove = findViewById(com.company.devicemgr.R.id.btnApproveSession);
        btnReject = findViewById(com.company.devicemgr.R.id.btnRejectSession);

        String sessionId = getIntent().getStringExtra(EXTRA_SESSION_ID);
        String requestType = getIntent().getStringExtra(EXTRA_REQUEST_TYPE);
        String requestedAt = getIntent().getStringExtra(EXTRA_REQUESTED_AT);
        String deadlineAt = getIntent().getStringExtra(EXTRA_DEADLINE_AT);
        tvMessage.setText("Pedido de sessão para " + friendlyType(requestType)
                + "\nSolicitado em: " + (requestedAt != null ? requestedAt : "-")
                + "\nResponder até: " + (deadlineAt != null ? deadlineAt : "-"));

        btnApprove.setOnClickListener(v -> confirmAndRespond(sessionId, true));
        btnReject.setOnClickListener(v -> confirmAndRespond(sessionId, false));
    }

    private void confirmAndRespond(String sessionId, boolean approve) {
        if (sessionId == null || sessionId.length() == 0) {
            Toast.makeText(this, "Sessão inválida", Toast.LENGTH_SHORT).show();
            finish();
            return;
        }

        new AlertDialog.Builder(this)
                .setTitle(approve ? "Aprovar sessão" : "Rejeitar sessão")
                .setMessage(approve
                        ? "Deseja iniciar uma sessão temporária de suporte?"
                        : "Deseja rejeitar este pedido?")
                .setPositiveButton("Confirmar", (d, which) -> submitResponse(sessionId, approve))
                .setNegativeButton("Cancelar", null)
                .show();
    }

    private void submitResponse(String sessionId, boolean approve) {
        btnApprove.setEnabled(false);
        btnReject.setEnabled(false);
        new Thread(() -> {
            try {
                SupportSessionApi.respond(this, sessionId, approve ? "approve" : "reject", approve ? 600 : 0);
                if (approve) {
                    AppRuntime.syncSupportSessionIndicator(this);
                }
                runOnUiThread(() -> {
                    Toast.makeText(this, approve ? "Sessão aprovada" : "Sessão rejeitada", Toast.LENGTH_SHORT).show();
                    finish();
                });
            } catch (Exception e) {
                runOnUiThread(() -> {
                    Toast.makeText(this, "Erro: " + e.getMessage(), Toast.LENGTH_LONG).show();
                    btnApprove.setEnabled(true);
                    btnReject.setEnabled(true);
                });
            }
        }).start();
    }

    private String friendlyType(String type) {
        if ("ambient_audio".equals(type)) return "áudio ambiente";
        return "partilha de ecrã";
    }
}
