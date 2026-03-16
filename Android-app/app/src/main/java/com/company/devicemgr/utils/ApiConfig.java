package com.company.devicemgr.utils;

public final class ApiConfig {
    private ApiConfig() {}

    // Mantém compatibilidade do endpoint atual; pode ser apontado para o novo backend PHP+MySQL.
    public static final String BASE_URL = "https://spymb.onrender.com";

    public static String api(String path) {
        if (path == null) return BASE_URL;
        if (!path.startsWith("/")) path = "/" + path;
        return BASE_URL + path;
    }
}
