# sistema_web (PHP + MySQL)

Estrutura unificada:
- `public/`: frontend estático + front controller (`index.php`).
- `app/`: backend PHP (config, bootstrap, schema MySQL).
- `storage/media/`: ficheiros de media enviados pelo app.

## Configuração
Use o ficheiro `.env.example` como base:

```bash
cp .env.example .env
```

Defina variáveis de ambiente:
- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
- `JWT_SECRET`
- `ADMIN_REGISTRATION_SECRET` (opcional)
- `APP_BASE_URL` (opcional, usado no link de recuperação)
- SMTP para recuperação de senha:
  - `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE`
  - `MAIL_FROM`, `MAIL_FROM_NAME`

## Criar base de dados (schema.sql)
Após configurar o `.env`, importe o schema:

```bash
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < app/schema.sql
```

> Se `DB_PASS` estiver vazio, use o comando sem `-p"$DB_PASS"`.

## Executar localmente
```bash
php -S 0.0.0.0:3000 -t public
```

## Dependências PHP (Composer)
Instale as dependências a partir da pasta `sistema_web`:

```bash
composer install
```

Se precisar atualizar ou adicionar manualmente o PHPMailer:

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


## Novo fluxo de alocação e subscrição
- O aparelho é alocado automaticamente ao utilizador autenticado via `POST /api/devices/auto-assign` usando **IMEI** (sem reivindicação manual).
- O IMEI é único no sistema para impedir duplicação do mesmo aparelho.
- A plataforma guarda e exibe metadados: **modelo, rede, operadora, nível de bateria, nível de sinal, estado online e última vez online**.
- O acesso aos dados de telemetria/media para utilizadores normais é liberado apenas com subscrição ativa por aparelho.

## Pagamento M-Pesa automático
- Endpoint: `POST /api/payments/mpesa/checkout`
- Valor fixo por aparelho: **800 MZN / 30 dias**
- Campos esperados no body JSON:
  - `deviceId`
  - `msisdn`
- Em aprovação automática, a subscrição do aparelho é estendida por 30 dias.

## Administração robusta
- Novo endpoint para painel admin: `GET /api/admin/overview`
- Retorna: total de utilizadores/aparelhos, online, subscrições ativas, receita mensal e lista de subscrições a expirar.


## Correções e desempenho adicionais
- A activity de permissões no Android agora mostra o nome do pacote e verifica visualmente se o **Acesso de utilização** e o **Acesso a notificações** foram concedidos.
- O manifesto Android passou a declarar `PACKAGE_USAGE_STATS`, melhorando a descoberta do app na tela de definições de uso.
- O frontend web foi otimizado com carregamentos paralelos (`Promise.all`) nas principais páginas do painel.
- O banco recebeu índices adicionais para consultas de aparelhos, subscrições e pagamentos.
- A funcionalidade de transmissão remota da tela em tempo real **não foi adicionada**, por envolver vigilância altamente sensível; mantive apenas uma área informativa no painel.
