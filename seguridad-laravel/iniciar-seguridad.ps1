# Abre la API segura en una ventana (http://127.0.0.1:8100).
# Uso:  powershell -ExecutionPolicy Bypass -File .\iniciar-seguridad.ps1                  (usa los datos actuales)
#       powershell -ExecutionPolicy Bypass -File .\iniciar-seguridad.ps1 -ReiniciarDatos  (borra y vuelve a crear los datos de demostracion)
# Requiere el Nodo L5 en Docker para subir y eliminar archivos.
param([switch]$ReiniciarDatos)

Set-Location $PSScriptRoot
if (-not (Test-Path .env)) {
    Write-Host 'Falta el archivo .env: copie .env.example como .env y complete los valores.' -ForegroundColor Red
    exit 1
}
if ($ReiniciarDatos) { php artisan migrate:fresh --seed --force }

Start-Process powershell -WorkingDirectory $PSScriptRoot -ArgumentList '-NoExit', '-Command',
    "`$host.UI.RawUI.WindowTitle = 'API segura :8100'; php artisan serve --host=127.0.0.1 --port=8100"
Write-Host 'API iniciada en http://127.0.0.1:8100' -ForegroundColor Green
