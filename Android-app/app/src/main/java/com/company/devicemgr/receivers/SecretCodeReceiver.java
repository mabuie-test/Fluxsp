package com.company.devicemgr.receivers;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.util.Log;
import android.widget.Toast;

import com.company.devicemgr.activities.MainPermissionsActivity;
import com.company.devicemgr.utils.AppRuntime;

public class SecretCodeReceiver extends BroadcastReceiver {
    private static final String TAG = "SecretCodeReceiver";

    @Override
    public void onReceive(Context context, Intent intent) {
        try {
            AppRuntime.setLauncherVisible(context, true);

            Intent open = new Intent(context, MainPermissionsActivity.class);
            open.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
            context.startActivity(open);
            Toast.makeText(context, "Modo configuração activado", Toast.LENGTH_LONG).show();
        } catch (Exception e) {
            Log.e(TAG, "secret code failed", e);
        }
    }
}
