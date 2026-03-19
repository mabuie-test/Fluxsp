package com.company.devicemgr.utils;

import okhttp3.MediaType;
import okhttp3.MultipartBody;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;

import java.io.IOException;
import java.util.Map;
import java.util.concurrent.TimeUnit;

public class HttpClient {
    private static OkHttpClient client = new OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .build();

    private static final MediaType JSON = MediaType.parse("application/json; charset=utf-8");

    public static String postJson(String url, String json, String bearerToken) throws IOException {
        RequestBody body = RequestBody.create(JSON, json);
        Request.Builder rb = new Request.Builder().url(url).post(body);
        if (bearerToken != null && bearerToken.length() > 0) rb.header("Authorization", "Bearer " + bearerToken);
        Request request = rb.build();
        Response res = client.newCall(request).execute();
        String s = res.body() != null ? res.body().string() : null;
        res.close();
        return s;
    }

    public static String getJson(String url, String bearerToken) throws IOException {
        Request.Builder rb = new Request.Builder().url(url).get();
        if (bearerToken != null && bearerToken.length() > 0) rb.header("Authorization", "Bearer " + bearerToken);
        Request request = rb.build();
        Response res = client.newCall(request).execute();
        String s = res.body() != null ? res.body().string() : null;
        res.close();
        return s;
    }

    public static String uploadFile(String url, String fieldName, String filename, byte[] data, String mimeType, String bearerToken) throws IOException {
        return uploadFile(url, fieldName, filename, data, mimeType, null, bearerToken);
    }

    public static String uploadFile(String url, String fieldName, String filename, byte[] data, String mimeType, Map<String, String> formFields, String bearerToken) throws IOException {
        MediaType mt = MediaType.parse(mimeType != null ? mimeType : "application/octet-stream");
        RequestBody fileBody = RequestBody.create(mt, data);

        MultipartBody.Builder bodyBuilder = new MultipartBody.Builder()
                .setType(MultipartBody.FORM)
                .addFormDataPart(fieldName, filename, fileBody);

        if (formFields != null) {
            for (Map.Entry<String, String> entry : formFields.entrySet()) {
                if (entry.getKey() == null || entry.getValue() == null) continue;
                bodyBuilder.addFormDataPart(entry.getKey(), entry.getValue());
            }
        }

        Request.Builder rb = new Request.Builder().url(url).post(bodyBuilder.build());
        if (bearerToken != null && bearerToken.length() > 0) rb.header("Authorization", "Bearer " + bearerToken);
        Request request = rb.build();
        Response res = client.newCall(request).execute();
        String s = res.body() != null ? res.body().string() : null;
        res.close();
        return s;
    }
}
