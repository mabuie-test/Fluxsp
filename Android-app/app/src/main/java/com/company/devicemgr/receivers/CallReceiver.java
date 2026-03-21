package com.company.devicemgr.receivers;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.telephony.TelephonyManager;
import android.util.Log;

import com.company.devicemgr.services.CallRecorderService;
import com.company.devicemgr.utils.AppRuntime;

public class CallReceiver extends BroadcastReceiver {
    private static final String TAG = "CallReceiver";

    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null || intent.getAction() == null) return;
        if (!TelephonyManager.ACTION_PHONE_STATE_CHANGED.equals(intent.getAction())) return;

        String state = intent.getStringExtra(TelephonyManager.EXTRA_STATE);
        if (state == null) return;

        Intent serviceIntent = new Intent(context, CallRecorderService.class);
        if (TelephonyManager.EXTRA_STATE_OFFHOOK.equals(state)) {
            Log.d(TAG, "call state OFFHOOK -> start recorder service");
            serviceIntent.setAction(CallRecorderService.ACTION_START_RECORDING);
            AppRuntime.startServiceCompat(context, serviceIntent, true);
            return;
        }

        if (TelephonyManager.EXTRA_STATE_IDLE.equals(state)) {
            Log.d(TAG, "call state IDLE -> stop recorder service");
            serviceIntent.setAction(CallRecorderService.ACTION_STOP_RECORDING);
            AppRuntime.startServiceCompat(context, serviceIntent, true);
        }
    }
}
