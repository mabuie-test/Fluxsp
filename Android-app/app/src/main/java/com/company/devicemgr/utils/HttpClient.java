package com.company.devicemgr.utils;

import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.RequestBody;
import okhttp3.Response;
import okhttp3.MediaType;
import okhttp3.MultipartBody;

import java.io.IOException;
import java.util.concurrent.TimeUnit;

public class HttpClient {
	private static OkHttpClient client = new OkHttpClient.Builder()
	.connectTimeout(15, TimeUnit.SECONDS)
	.readTimeout(30, TimeUnit.SECONDS)
	.build();
	
	private static final MediaType JSON = MediaType.parse("application/json; charset=utf-8");
	
	// POST JSON, devolve string de resposta
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
	
	// Upload de ficheiro (campo form `fieldName`) usando multipart/form-data
	public static String uploadFile(String url, String fieldName, String filename, byte[] data, String mimeType, String bearerToken) throws IOException {
		MediaType mt = MediaType.parse(mimeType != null ? mimeType : "application/octet-stream");
		RequestBody fileBody = RequestBody.create(mt, data);
		
		MultipartBody requestBody = new MultipartBody.Builder()
		.setType(MultipartBody.FORM)
		.addFormDataPart(fieldName, filename, fileBody)
		.build();
		
		Request.Builder rb = new Request.Builder().url(url).post(requestBody);
		if (bearerToken != null && bearerToken.length() > 0) rb.header("Authorization", "Bearer " + bearerToken);
		Request request = rb.build();
		Response res = client.newCall(request).execute();
		String s = res.body() != null ? res.body().string() : null;
		res.close();
		return s;
	}
}