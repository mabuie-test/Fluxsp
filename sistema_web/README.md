# sistema_web (PHP + MySQL)

Estrutura unificada:
- `public/`: frontend estático + front controller (`index.php`).
- `app/`: backend PHP (config, bootstrap, schema MySQL).
- `storage/media/`: ficheiros de media enviados pelo app.

## Configuração
Defina variáveis de ambiente:
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `JWT_SECRET`
- `ADMIN_REGISTRATION_SECRET` (opcional)

## Executar localmente
```bash
php -S 0.0.0.0:3000 -t public
```

Todas as rotas atuais continuam compatíveis sob `/api/*`:
- `/api/auth/*`
- `/api/devices/*`
- `/api/telemetry/*`
- `/api/payments/*`
- `/api/media/*`

A criação de schema MySQL ocorre automaticamente no primeiro request.


## Compatibilidade
- As rotas do app Android continuam em `/api/*` sem alteração de contrato principal.
- A estrutura antiga (`backend/` e `web-frontend/`) foi removida para evitar duplicação; agora toda a aplicação web roda a partir de `public/` com backend em `app/`.
