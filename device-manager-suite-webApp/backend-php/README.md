# backend-php (PHP + MySQL)

Conversão do backend original Node.js + MongoDB para PHP + MySQL, mantendo os mesmos endpoints `/api` usados pelo frontend web e app Android.

## Requisitos
- PHP 8.1+
- Extensão PDO MySQL
- MySQL 8+

## Configuração
Variáveis de ambiente suportadas:
- `MYSQL_HOST` (default `127.0.0.1`)
- `MYSQL_PORT` (default `3306`)
- `MYSQL_DATABASE` (default `devicemgr`)
- `MYSQL_USER` (default `root`)
- `MYSQL_PASSWORD` (default vazio)
- `JWT_SECRET`
- `ADMIN_REGISTRATION_SECRET`
- `MEDIA_DIR` (diretório onde ficam os uploads)

## Inicializar BD
Importar o schema:

```bash
mysql -u root -p devicemgr < sql/schema.sql
```

## Executar localmente
```bash
cd backend-php/public
php -S 0.0.0.0:3000
```

Depois, as rotas ficam em `http://localhost:3000/api/...`.
