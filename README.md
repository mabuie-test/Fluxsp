# device-manager-suite

Suite completa com:

- app Android para recolha de telemetria, sessões remotas, captura live e upload de media;
- backend PHP/MySQL para autenticação, pagamentos, media, observabilidade e controlo de sessões;
- frontend web para administração e gestão por utilizador;
- realtime interno em PHP/SSE dentro de `sistema_web`, compatível com hosting compartilhado.

## Estrutura

- `Android-app/` — aplicação Android.
- `sistema_web/` — backend PHP + frontend web.

## Arranque local completo

### 1) Backend web

```bash
cd sistema_web
php -S 0.0.0.0:3000 -t public
```

### 2) Variáveis de ambiente importantes

Backend PHP:

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `JWT_SECRET`
- `ADMIN_REGISTRATION_SECRET`
- `APP_BASE_URL`
- `REALTIME_ENABLED` — ativa/desativa o realtime interno em PHP/SSE
- `REALTIME_STREAM_TTL` — validade do token curto usado pelo EventSource
- `REALTIME_STREAM_MAX_DURATION` — duração máxima de cada ligação SSE antes de reconexão
- `DEBITO_BASE_URL`, `DEBITO_API_TOKEN`, `DEBITO_WALLET_ID`, `DEBITO_CALLBACK_URL`

### 3) Android

```bash
cd Android-app
bash ./gradlew :app:assembleDebug
```

> Nota: neste ambiente o download do Gradle wrapper pode falhar por restrições de proxy. No repositório, o wrapper deve ser executado com `bash ./gradlew` ou com o bit executável ativo.
