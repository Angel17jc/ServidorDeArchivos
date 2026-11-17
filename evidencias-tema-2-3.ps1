# Evidencias IS-803 Tema 2.3 - Traductor de metadatos de archivos (Nodo L5)
# Uso:  powershell -ExecutionPolicy Bypass -File .\evidencias-tema-2-3.ps1            (pausa para tomar cada captura)
#       powershell -ExecutionPolicy Bypass -File .\evidencias-tema-2-3.ps1 -SinPausa
param([switch]$SinPausa)

$ErrorActionPreference = 'Continue'
Set-Location $PSScriptRoot
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$apiKey = 'clave-prueba-l5'
if (Test-Path .env) {
    $line = Get-Content .env | Where-Object { $_ -match '^FILES_API_KEY=' } | Select-Object -First 1
    if ($line) { $apiKey = $line.Split('=', 2)[1].Trim() }
}

function Pantalla($titulo, $detalle, [scriptblock]$comandos) {
    if (-not $SinPausa) { Clear-Host }
    Write-Host ('=' * 78) -ForegroundColor Cyan
    Write-Host " $titulo" -ForegroundColor Cyan
    Write-Host " $detalle" -ForegroundColor Cyan
    Write-Host " Fecha: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')   |   Equipo: $env:COMPUTERNAME   |   Rama: $(git branch --show-current)" -ForegroundColor Cyan
    Write-Host ('=' * 78) -ForegroundColor Cyan
    & $comandos
    Write-Host ''
    if (-not $SinPausa) { Read-Host 'Toma la captura y presiona Enter para continuar' | Out-Null }
}

function Demo($caso) {
    docker exec -e CASO=$caso -e FILES_API_KEY=$apiKey l3-sim node /tmp/demo-tema-2-3.js
}

Write-Host 'Levantando L5 y el nodo de prueba l3-sim...' -ForegroundColor Green
docker compose --profile pruebas up -d --no-build | Out-Null
Start-Sleep -Seconds 3
# Se copia el script al nodo l3-sim (enviarlo por tuberia desde PowerShell 5.1 corrompe las tildes)
docker cp .\l5-file-server\scripts\demo-tema-2-3.js l3-sim:/tmp/demo-tema-2-3.js | Out-Null

Pantalla 'PRUEBAS AUTOMATIZADAS - NormalizadorArchivos (P1, P2, P3)' 'npm test  ->  pruebas unitarias del traductor y pruebas HTTP del servidor' {
    Push-Location .\l5-file-server
    npm test
    Pop-Location
}

Pantalla 'P1 - Entrada A valida (almacenamiento local de L5)' 'Origen: l3-sim 192.168.10.13  ->  Destino: POST http://192.168.10.15:8080/upload' {
    Demo 'P1'
}

Pantalla 'P2 - Entrada B equivalente (almacenamiento externo, formato S3/MinIO)' 'Origen: l3-sim 192.168.10.13  ->  Destino: POST http://192.168.10.15:8080/integraciones/almacen-externo' {
    Demo 'P2'
}

Pantalla 'P3 - Campo faltante o valor invalido: error de validacion explicito' 'Origen: l3-sim 192.168.10.13  ->  Destino: POST http://192.168.10.15:8080/integraciones/almacen-externo' {
    Demo 'P3'
}

Write-Host 'Evidencias completadas.' -ForegroundColor Green
