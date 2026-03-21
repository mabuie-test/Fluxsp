# device-manager-suite

Suite completa com:

- app Android para recolha de telemetria, sessões remotas, captura live e upload de media;
- backend PHP/MySQL para autenticação, pagamentos, media, observabilidade e controlo de sessões;
- frontend web para administração e gestão por utilizador;
- hub websocket simples para refresco live em tempo real.

## Estrutura

- `Android-app/` — aplicação Android.
- `sistema_web/` — backend PHP + frontend web.
- `realtime/` — hub websocket para notificações live.

## Arranque local completo

### 1) Backend web

```bash
cd sistema_web
php -S 0.0.0.0:3000 -t public
```

### 2) Hub realtime

```bash
REALTIME_PORT=8091 \
REALTIME_SHARED_SECRET=change-me \
node realtime/ws_hub.js
```

### 3) Variáveis de ambiente importantes

Backend PHP:

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `JWT_SECRET`
- `ADMIN_REGISTRATION_SECRET`
- `APP_BASE_URL`
- `REALTIME_WS_URL` — URL websocket consumida pelo frontend, por exemplo `ws://127.0.0.1:8091`
- `REALTIME_PUBLISH_URL` — URL HTTP de publish do hub, por exemplo `http://127.0.0.1:8091/publish`
- `REALTIME_SHARED_SECRET` — segredo partilhado entre PHP e o hub websocket
- `DEBITO_BASE_URL`, `DEBITO_API_TOKEN`, `DEBITO_WALLET_ID`, `DEBITO_CALLBACK_URL`

### 4) Android

```bash
cd Android-app
bash ./gradlew :app:assembleDebug
```

> Nota: neste ambiente o download do Gradle wrapper pode falhar por restrições de proxy. No repositório, o wrapper deve ser executado com `bash ./gradlew` ou com o bit executável ativo.
