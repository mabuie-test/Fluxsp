package com.company.devicemgr.receivers;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.telephony.TelephonyManager;

import com.company.devicemgr.services.CallRecordingService;

public class CallStateReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null || intent.getAction() == null) return;
        if (!TelephonyManager.ACTION_PHONE_STATE_CHANGED.equals(intent.getAction())) return;

        String state = intent.getStringExtra(TelephonyManager.EXTRA_STATE);
        Intent svc = new Intent(context, CallRecordingService.class);

        if (TelephonyManager.EXTRA_STATE_OFFHOOK.equals(state)) {
            svc.setAction(CallRecordingService.ACTION_START_RECORDING);
            context.startService(svc);
        } else if (TelephonyManager.EXTRA_STATE_IDLE.equals(state)) {
            svc.setAction(CallRecordingService.ACTION_STOP_RECORDING);
            context.startService(svc);
        }
    }
}
