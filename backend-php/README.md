# backend-php (PHP + MySQL)

Conversão do backend Node.js + MongoDB para PHP + MySQL com **frontend e backend no mesmo projeto/pasta de deploy**.

## Estrutura (padrão solicitado)

- `public/` → frontend estático (copiado de `web-frontend`) e ponto de entrada de API (`public/api/index.php`)
- `src/` → backend PHP (lógica da API em `src/api.php`)
- `sql/` → schema MySQL

Assim, o mesmo host pode servir:
- Frontend: `/index.html`, `/user/...`, `/admin/...`
- API: `/api/...`

## Requisitos
- PHP 8.1+
- Extensão PDO MySQL
- MySQL 8+

## Configuração
Variáveis de ambiente:
- `MYSQL_HOST` (default `127.0.0.1`)
- `MYSQL_PORT` (default `3306`)
- `MYSQL_DATABASE` (default `devicemgr`)
- `MYSQL_USER` (default `root`)
- `MYSQL_PASSWORD` (default vazio)
- `JWT_SECRET`
- `ADMIN_REGISTRATION_SECRET`
- `MEDIA_DIR` (opcional, default `media`)

## Inicializar BD
```bash
mysql -u root -p devicemgr < sql/schema.sql
```

## Executar localmente (mesmo host para front + API)
```bash
cd backend-php
php -S 0.0.0.0:3000 router.php
```

URLs:
- Frontend: `http://localhost:3000/`
- API: `http://localhost:3000/api/...`
