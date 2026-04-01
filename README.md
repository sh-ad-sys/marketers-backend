# marketers-backend

## Local startup

If your local PHP install is missing a loaded `php.ini`, the backend can fail with errors such as:

- `Undefined constant PDO::MYSQL_ATTR_SSL_CA`
- `Database connection failed.`

Start the backend with the bundled PowerShell launcher so `openssl` and `pdo_mysql` are enabled:

```powershell
powershell -ExecutionPolicy Bypass -File .\start-local-backend.ps1
```
