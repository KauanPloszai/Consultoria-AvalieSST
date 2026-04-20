# AvaliESST com MySQL + PHP

Este projeto agora esta preparado para usar PHP com MySQL em vez de localStorage.

## 1. Banco de dados

1. Crie o banco executando o arquivo `database.sql`.
2. O script ja cria o usuario administrador inicial.

Credenciais iniciais:

- E-mail: `admin@avaliiesst.com`
- Senha: `Admin@123`

## 2. Configuracao do PHP

Edite o arquivo `config.php` se o seu MySQL nao estiver com os valores abaixo:

- host: `127.0.0.1`
- porta: `3306`
- banco: `avaliiesst`
- usuario: `root`
- senha: ``

Voce tambem pode usar variaveis de ambiente:

- `AVALIESST_DB_HOST`
- `AVALIESST_DB_PORT`
- `AVALIESST_DB_NAME`
- `AVALIESST_DB_USER`
- `AVALIESST_DB_PASS`

## 3. Rodando localmente

Com PHP instalado:

```bash
php -S localhost:8000
```

Depois abra:

- `http://localhost:8000/index.html`

## 4. Endpoints criados

- `api/login.php`
- `api/session.php`
- `api/companies.php`
- `api/forms.php`
- `api/access-codes.php`
- `api/access-code.php`

## 5. Codigos de acesso

O painel `Geracao de Acesso` agora:

- cria novos codigos no MySQL
- revoga codigos ativos
- regenera codigos
- lista historico de uso

Codigo de exemplo para teste:

- `TECH-OP1-X789-2026`

## 6. Observacao

No ambiente em que este patch foi gerado, o executavel `php` nao estava instalado no PATH.
Por isso, os arquivos foram preparados, mas a execucao do backend precisa ser validada na sua maquina.
