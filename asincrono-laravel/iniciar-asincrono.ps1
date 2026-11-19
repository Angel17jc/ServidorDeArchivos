# Inicia la practica asincrona: broker RabbitMQ (Docker), API productora (:8200) y consumidor.
# Uso:  powershell -ExecutionPolicy Bypass -File .\iniciar-asincrono.ps1                 (todo)
#       powershell -ExecutionPolicy Bypass -File .\iniciar-asincrono.ps1 -SoloConsumidor (vuelve a abrir solo el consumidor)
# Requiere el Nodo L5 en Docker: de alli se descargan las imagenes y alli se suben las miniaturas.
param([switch]$SoloConsumidor)

Set-Location $PSScriptRoot
if (-not (Test-Path .env)) {
    Write-Host 'Falta el archivo .env: copie .env.example como .env y complete los valores.' -ForegroundColor Red
    exit 1
}

function Abrir($titulo, $comando) {
    Start-Process powershell -WorkingDirectory $PSScriptRoot -ArgumentList '-NoExit', '-Command',
        "`$host.UI.RawUI.WindowTitle = '$titulo'; Write-Host '$titulo' -ForegroundColor Cyan; $comando"
}

if (-not $SoloConsumidor) {
    docker compose up -d
    Abrir 'API productora :8200' 'php artisan serve --host=127.0.0.1 --port=8200'
}
Abrir 'Consumidor de miniaturas' 'php artisan miniaturas:consumir'

Write-Host 'Panel de RabbitMQ: http://127.0.0.1:15672 (usuario y clave en RABBITMQ_USER / RABBITMQ_PASSWORD del .env)' -ForegroundColor Green
