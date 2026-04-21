# Moz Acad

**Tagline:** Plataforma inteligente de apoio à escrita científica, normalização institucional e geração documental académica.

## Visão Geral
Moz Acad é uma base de produção em PHP 8.2+ com arquitectura MVC modular para gestão de pedidos académicos, pricing inteligente, facturação, pagamentos M-Pesa C2B via Débito, pipeline de geração assistida e entrega de DOCX formatado por instituição.

## Stack
- PHP 8.2+
- MySQL/MariaDB
- Composer
- GuzzleHTTP
- PHPWord
- vlucas/phpdotenv
- Bootstrap 5

## Arquitectura
- **Controllers leves**: validação de entrada e delegação.
- **Services fortes**: pricing, descontos, integração Débito, rule-engine, geração e humanização.
- **Repositories**: acesso a dados e queries orientadas ao domínio.
- **DTOs**: transporte tipado entre camadas.
- **Jobs/Cron**: polling de pagamentos e processamento assíncrono.

## Estrutura de Pastas
```text
/public
/app
/app/Controllers
/app/Models
/app/Repositories
/app/Services
/app/Jobs
/app/Middleware
/app/Helpers
/app/DTOs
/config
/routes
/database/migrations
/database/seeders
/storage/generated
/storage/templates
/storage/logs
```

## Módulos Implementados
1. Autenticação e sessão segura
2. Autorização por roles (base para middleware)
3. Gestão académica (instituições, cursos, disciplinas, níveis)
4. Catálogo de tipos de trabalho (inclui monografia com revisão humana obrigatória)
5. Motor de estruturas documentais
6. Motor de normas institucionais com resolução por camadas
7. Gestão de pedidos (wizard multi-etapas no backend)
8. Gestão de anexos
9. Pricing com breakdown detalhado
10. Facturação
11. Pagamentos Débito M-Pesa C2B
12. Polling principal de status de pagamento
13. Webhook opcional complementar
14. Descontos por utilizador seleccionado
15. Pipeline de geração assistida + humanização pt_MZ
16. DOCX com PHPWord
17. Revisões e fila humana
18. Auditoria e relatórios (schema preparado)

## Instalação
```bash
cd sistema_web
composer install
cp .env.example .env
```

Crie BD e execute migrations + seeders:
```bash
mysql -u root -p moz_acad < database/migrations/001_create_core_tables.sql
mysql -u root -p moz_acad < database/migrations/002_create_business_tables.sql
mysql -u root -p moz_acad < database/migrations/003_create_financial_and_ops_tables.sql
mysql -u root -p moz_acad < database/seeders/001_base_seed.sql
mysql -u root -p moz_acad < database/seeders/002_pricing_and_structures_seed.sql
```

## Configuração `.env`
Use o `.env.example` com todos os parâmetros obrigatórios:
- App, DB, armazenamento
- Pricing base e multiplicadores
- Débito (token estático + login dinâmico)
- Callback opcional

## Débito M-Pesa C2B
### Endpoints suportados
- `POST /api/v1/login`
- `POST /api/v1/wallets/{wallet_id}/c2b/mpesa`
- `GET /api/v1/transactions/{debito_reference}/status`

### Estratégia de autenticação
- Se `DEBITO_USE_STATIC_TOKEN=true` e `DEBITO_TOKEN` preenchido, usa bearer estático.
- Caso contrário, faz login dinâmico com `DEBITO_EMAIL` e `DEBITO_PASSWORD`.

### Polling como mecanismo principal
`PaymentStatusPollingService` consulta pagamentos pendentes e actualiza `payments.status` + `orders.status`.

### Webhook opcional
Endpoint: `POST /webhooks/debito`.
- Regista payload para auditoria.
- Complementa o polling.
- Não substitui a lógica principal.

## Cron Jobs sugeridos
```bash
* * * * * /usr/bin/php /path/sistema_web/public/cron_payment_polling.php >> /path/sistema_web/storage/logs/cron.log 2>&1
```

## Fluxo de Monografia
- Monografia cadastrada com `allows_full_auto_generation = false` e `requires_human_review = true`.
- Após geração assistida, pedido entra em `awaiting_human_review`.
- Só após aprovação humana vai para `ready`.

## Deploy em host PHP tradicional
1. Aponte document root para `public/` (ou use `public_html` com rewrite).
2. Suba `vendor/`, `app/`, `config/`, `routes/`, `database/`, `storage/`.
3. Configure permissões em `storage/`.
4. Registe cron para polling.

## Observações de Extensão
- Adicionar middleware RBAC por rota.
- Completar CRUD admin com controllers dedicados por módulo.
- Integrar provider de IA real no `OpenAIProvider`.
- Adicionar fila assíncrona para etapas longas de geração.
