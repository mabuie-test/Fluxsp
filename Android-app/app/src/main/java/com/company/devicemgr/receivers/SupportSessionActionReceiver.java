package com.company.devicemgr.receivers;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

import com.company.devicemgr.utils.AppRuntime;

public class SupportSessionActionReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        AppRuntime.requestSupportSessionStop(context);
    }
}
