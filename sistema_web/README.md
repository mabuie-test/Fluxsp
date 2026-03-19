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
- `APP_BASE_URL` (opcional, usado no link de recuperação)
- SMTP para recuperação de senha:
  - `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE`
  - `MAIL_FROM`, `MAIL_FROM_NAME`

## Executar localmente
```bash
php -S 0.0.0.0:3000 -t public
```

## Dependência de email (PHPMailer)
```bash
composer require phpmailer/phpmailer
```

## Compatibilidade
- As rotas do app Android continuam em `/api/*` sem alteração de contrato principal.
- A estrutura antiga (`backend/` e `web-frontend/`) foi removida para evitar duplicação; agora toda a aplicação web roda a partir de `public/` com backend em `app/`.

## Rotas de autenticação adicionadas
- `POST /api/auth/forgot-password`
- `POST /api/auth/reset-password`
- Páginas web:
  - `/login.html`
  - `/register.html`
  - `/forgot-password.html`
  - `/reset-password.html`

## Robustez adicionada
- Endpoint de health check: `GET /api/health`.
- Validação de acesso por owner/admin para endpoints sensíveis de device, telemetry e media.
- Operação de processamento de pagamentos com transação no banco.

## Associação automática do dispositivo
- A app Android faz associação automática do `deviceId` ao utilizador no login via `POST /api/devices/auto-assign`.
- O painel web deixa de depender de reivindicação manual do aparelho.
- A app usa um identificador estável do Android para evitar que o mesmo aparelho seja adicionado várias vezes.

## Sessões de suporte aprovadas localmente
- O backend pode criar um pedido de sessão temporária para `screen` ou `ambient_audio` via `POST /api/support-sessions/request`.
- A app Android consulta pedidos pendentes, mostra um pedido local ao utilizador e exige aprovação manual em cada sessão.
- Sessões aprovadas expiram automaticamente, ficam visíveis com notificação persistente e podem ser interrompidas a qualquer momento pela app ou pelo painel.
