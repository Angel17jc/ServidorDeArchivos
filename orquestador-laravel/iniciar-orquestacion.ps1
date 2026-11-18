# Abre una ventana por proceso: servicios de usuarios, estados y catalogo, y el orquestador Laravel.
# Uso:  powershell -ExecutionPolicy Bypass -File .\iniciar-orquestacion.ps1
#       powershell -ExecutionPolicy Bypass -File .\iniciar-orquestacion.ps1 -EstadosLento   (estados tarda 5 s)
# Para simular que un servicio no responde: en su ventana presiona Ctrl+C.
param([switch]$EstadosLento)

Set-Location $PSScriptRoot

function Abrir($titulo, $comando) {
    Start-Process powershell -WorkingDirectory $PSScriptRoot -ArgumentList '-NoExit', '-Command',
        "`$host.UI.RawUI.WindowTitle = '$titulo'; Write-Host '$titulo' -ForegroundColor Cyan; $comando"
}

$retraso = if ($EstadosLento) { "`$env:RETRASO_SEGUNDOS = '5'; " } else { '' }

Abrir 'Servicio usuarios :8001' 'php -S 127.0.0.1:8001 servicios/usuarios.php'
Abrir 'Servicio estados :8002' ($retraso + 'php -S 127.0.0.1:8002 servicios/estados.php')
Abrir 'Servicio catalogo :8003' 'php -S 127.0.0.1:8003 servicios/catalogo.php'
Abrir 'Orquestador Laravel :8000' 'php artisan serve --host=127.0.0.1 --port=8000'

Write-Host 'Procesos iniciados. Prueba:  curl.exe -i -H "X-Correlation-ID: clase-101" http://127.0.0.1:8000/api/resumen-solicitud/101' -ForegroundColor Green
