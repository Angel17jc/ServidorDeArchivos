# Inicia la practica de enrutamiento (tema 3.2):
#   Nodo L5 (Docker, del proyecto principal), broker RabbitMQ (Docker), API productora (:8300)
#   una ventana por consumidor (miniaturas, comprobantes y limpieza) y otra para revision y seguimiento.
# Uso:  powershell -ExecutionPolicy Bypass -File .\iniciar-enrutamiento.ps1                    (todo)
#       powershell -ExecutionPolicy Bypass -File .\iniciar-enrutamiento.ps1 -SoloConsumidores  (reabre los consumidores)
param([switch]$SoloConsumidores)

Set-Location $PSScriptRoot
if (-not (Test-Path .env)) {
    Write-Host 'Falta el archivo .env: copie .env.example como .env y complete los valores.' -ForegroundColor Red
    exit 1
}

function Abrir($titulo, $comando) {
    Start-Process powershell -WorkingDirectory $PSScriptRoot -ArgumentList '-NoExit', '-Command',
        "`$host.UI.RawUI.WindowTitle = '$titulo'; Write-Host '$titulo' -ForegroundColor Cyan; $comando"
}

if (-not $SoloConsumidores) {
    Write-Host 'Nodo L5 (servidor de archivos del proyecto)...' -ForegroundColor Yellow
    docker compose -f ..\docker-compose.yml up -d l5-files
    Write-Host 'Broker RabbitMQ...' -ForegroundColor Yellow
    docker compose up -d

    $listo = $false
    for ($i = 0; $i -lt 40 -and -not $listo; $i++) {
        docker exec -u rabbitmq rabbitmq-enrutamiento rabbitmq-diagnostics -q check_port_connectivity *> $null
        if ($LASTEXITCODE -eq 0) { $listo = $true } else { Start-Sleep -Seconds 2 }
    }
    if (-not $listo) { Write-Host 'RabbitMQ no respondio a tiempo.' -ForegroundColor Red; exit 1 }

    Abrir 'API productora :8300' 'php artisan serve --host=127.0.0.1 --port=8300'
}
Abrir 'Consumidor miniaturas' 'php artisan eventos:consumir miniaturas'
Abrir 'Consumidor comprobantes' 'php artisan eventos:consumir comprobantes'
Abrir 'Consumidor limpieza' 'php artisan eventos:consumir limpieza'
if (-not $SoloConsumidores) {
    Abrir 'Revision y seguimiento' "Write-Host 'Comandos: php artisan eventos:historial   php artisan eventos:revisar   php artisan eventos:colas' -ForegroundColor Gray"
}

Write-Host 'Listo. Panel de RabbitMQ: http://127.0.0.1:15672 (usuario y clave en RABBITMQ_USER / RABBITMQ_PASSWORD del .env)' -ForegroundColor Green
