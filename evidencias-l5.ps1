# Evidencias del Nodo L5 (Servidor de archivos) - Guia IS-803 tema 1.4
# Uso:  powershell -ExecutionPolicy Bypass -File .\evidencias-l5.ps1            (pausa entre evidencias para tomar capturas)
#       powershell -ExecutionPolicy Bypass -File .\evidencias-l5.ps1 -SinPausa  (todo seguido)
param([switch]$SinPausa)

$ErrorActionPreference = 'Continue'
Set-Location $PSScriptRoot
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$apiKey = ''
if (Test-Path .env) {
    $line = Get-Content .env | Where-Object { $_ -match '^FILES_API_KEY=' } | Select-Object -First 1
    if ($line) { $apiKey = $line.Split('=', 2)[1].Trim() }
}
$lanIp = (Get-NetIPAddress -AddressFamily IPv4 -InterfaceAlias 'Wi-Fi', 'Ethernet' -ErrorAction SilentlyContinue |
    Where-Object { $_.IPAddress -notlike '169.254*' } | Select-Object -First 1).IPAddress

function Evidencia($titulo, $origen, $destino, [scriptblock]$comandos) {
    if (-not $SinPausa) { Clear-Host }
    Write-Host ('=' * 78) -ForegroundColor Cyan
    Write-Host " $titulo" -ForegroundColor Cyan
    Write-Host " Origen: $origen   |   Destino: $destino" -ForegroundColor Cyan
    Write-Host " Fecha: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')   |   Equipo: $env:COMPUTERNAME" -ForegroundColor Cyan
    Write-Host ('=' * 78) -ForegroundColor Cyan
    & $comandos
    Write-Host ''
    if (-not $SinPausa) { Read-Host 'Toma la captura y presiona Enter para continuar' | Out-Null }
}

function Cmd($texto) { Write-Host "`n> $texto" -ForegroundColor Yellow }

Write-Host 'Levantando L5 y los nodos de prueba (l3-sim, l1-sim)...' -ForegroundColor Green
docker compose --profile pruebas up -d | Out-Null
Start-Sleep -Seconds 3

Evidencia 'EVIDENCIA 1 - Configuracion IP del Nodo L5' 'l5-files' 'red_nodos 192.168.10.0/24' {
    Cmd 'docker exec l5-files hostname'
    docker exec l5-files hostname
    Cmd 'docker exec l5-files hostname -i'
    docker exec l5-files hostname -i
    Cmd 'docker exec l5-files ip -4 addr show eth0'
    docker exec l5-files ip -4 addr show eth0
    Cmd 'docker network inspect red_nodos (nodos conectados)'
    docker network inspect red_nodos --format '{{range .Containers}}{{.Name}}  ->  {{.IPv4Address}}{{println}}{{end}}'
    Cmd "IP del equipo anfitrion en la red local (Wi-Fi): $lanIp"
}

Evidencia 'EVIDENCIA 2a - Ping L3 (backend) -> L5 (archivos)' 'l3-sim 192.168.10.13' 'l5-files 192.168.10.15' {
    Cmd 'docker exec l3-sim ping -c 4 192.168.10.15'
    docker exec l3-sim ping -c 4 192.168.10.15
}

Evidencia 'EVIDENCIA 2b - Ping L1 (NGINX) -> L5 (archivos)' 'l1-sim 192.168.10.11' 'l5-files 192.168.10.15' {
    Cmd 'docker exec l1-sim ping -c 4 192.168.10.15'
    docker exec l1-sim ping -c 4 192.168.10.15
}

Evidencia 'EVIDENCIA 2c - Ping en sentido contrario L5 -> L3' 'l5-files 192.168.10.15' 'l3-sim 192.168.10.13' {
    Cmd 'docker exec l5-files ping -c 4 192.168.10.13'
    docker exec l5-files ping -c 4 192.168.10.13
}

Evidencia 'EVIDENCIA 3 - Servicio L5 ejecutandose en 192.168.10.15:8080' 'l5-files' '0.0.0.0:8080' {
    Cmd 'docker compose ps l5-files'
    docker compose ps l5-files
    Cmd 'docker exec l5-files netstat -tln   (escucha en 0.0.0.0, no solo en localhost)'
    docker exec l5-files netstat -tln
    Cmd 'docker logs l5-files (arranque)'
    docker logs l5-files 2>&1 | Select-Object -First 2
}

Evidencia 'EVIDENCIA 4a - L3 consume /health de L5' 'l3-sim 192.168.10.13' 'http://192.168.10.15:8080/health' {
    Cmd 'docker exec l3-sim curl -s http://192.168.10.15:8080/health'
    docker exec l3-sim curl -s http://192.168.10.15:8080/health
    Write-Host ''
}

$script:storageKey = ''
Evidencia 'EVIDENCIA 4b - L3 sube un archivo a L5 (flujo 6.3 del documento)' 'l3-sim 192.168.10.13' 'POST http://192.168.10.15:8080/files' {
    docker exec l3-sim sh -c "printf '%%PDF-1.4 comprobante de prueba' > /tmp/comprobante.pdf"
    Cmd 'curl sin x-api-key (debe rechazarse)'
    docker exec l3-sim curl -s -w '  [HTTP %{http_code}]' -F 'file=@/tmp/comprobante.pdf;type=application/pdf' http://192.168.10.15:8080/files
    Write-Host ''
    Cmd 'curl -H "x-api-key: ***" -F file=@comprobante.pdf "http://192.168.10.15:8080/files?folder=payments"'
    $resp = docker exec l3-sim curl -s -H "x-api-key: $apiKey" -F 'file=@/tmp/comprobante.pdf;type=application/pdf' 'http://192.168.10.15:8080/files?folder=payments'
    $resp
    try { $script:storageKey = ($resp | ConvertFrom-Json).storage_key } catch {}
}

Evidencia 'EVIDENCIA 4c - L1 descarga el archivo desde L5 (ruta /files/)' 'l1-sim 192.168.10.11' "GET http://192.168.10.15:8080/files/$script:storageKey" {
    Cmd "docker exec l1-sim curl -i http://192.168.10.15:8080/files/$script:storageKey"
    docker exec l1-sim curl -s -i "http://192.168.10.15:8080/files/$script:storageKey"
    Write-Host ''
    Cmd 'docker logs l5-files --tail 6   (L5 registra la IP de origen de cada solicitud)'
    docker logs l5-files --tail 6 2>&1
}

Evidencia 'EVIDENCIA 4d - Acceso a L5 por la IP de la red local' "$env:COMPUTERNAME" "http://${lanIp}:8080/health" {
    Cmd "curl.exe -s http://${lanIp}:8080/health"
    curl.exe -s "http://${lanIp}:8080/health"
    Write-Host ''
    Write-Host "`nDesde OTRO computador o un movil en la misma Wi-Fi abre: http://${lanIp}:8080/health" -ForegroundColor Green
}

Write-Host 'Evidencias completadas.' -ForegroundColor Green
