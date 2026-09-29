# Nodo L5 — Servidor de archivos

Nodo L5 de la arquitectura de 5 nodos. Almacena y sirve archivos binarios (fotos de productos, comprobantes de pago, etc.).

| Campo | Valor |
|---|---|
| Nodo | L5 — Servidor de archivos |
| Equipo / SO | Contenedor Docker (Node 22 sobre Alpine Linux) |
| Componente | Node.js + Express + Multer |
| IP (red Docker `red_nodos`) | 192.168.10.15 |
| Puerto | 8080 (publicado en el anfitrión como 8080) |
| Protocolo | HTTP |
| Se comunica con | L3 (carga, consulta, borrado) y L1 (descarga por `/files/`) |

## Levantar el nodo

```bash
cp .env.example .env              # cambiar FILES_API_KEY (la misma clave la usa L3)
docker compose up -d --build      # solo L5
docker compose --profile pruebas up -d --build   # L5 + contenedores de prueba l3-sim (.13) y l1-sim (.11)
docker compose logs -f l5-files   # ver solicitudes con la IP de origen
docker compose --profile pruebas down            # detener (los archivos se conservan en el volumen)
```

## API

| Método | Ruta | Quién la usa | Clave `x-api-key` | Respuesta |
|---|---|---|---|---|
| GET | `/health` | Cualquiera / L1 | No | `200 {status:"ok", node:"L5", ...}` |
| POST | `/files?folder=<carpeta>` | L3 | **Sí** | `201 {storage_key, url, original_name, mime_type, size}` |
| GET | `/files/<carpeta>/<archivo>` | L1 y L3 | No | El archivo binario |
| DELETE | `/files/<carpeta>/<archivo>` | L3 | **Sí** | `204` |

- Campo multipart: `file`. Carpetas: `products`, `payments`, `users`, `messages`, `general`.
- Tipos permitidos: JPG, PNG, WEBP y PDF. Tamaño máximo: 10 MB (`MAX_FILE_SIZE_MB`).
- Errores: `400` (sin archivo o carpeta inválida), `401` (clave), `404`, `413` (tamaño), `415` (tipo).
- Los metadatos (nombre original, tamaño, a qué producto o pago pertenece) **los guarda L3 en L4**; L5 solo guarda el archivo.

Contrato para L3 y configuración de NGINX para L1: ver [docs/](docs/).

## Evidencias para la guía (IS-803, tema 1.4)

**Forma rápida:** `powershell -ExecutionPolicy Bypass -File .\evidencias-l5.ps1` muestra cada evidencia en su propia pantalla (con origen, destino, fecha y equipo) y espera Enter para que tomes la captura. Las explicaciones de cada captura ya están redactadas en [Informe_Nodo_L5.docx](Informe_Nodo_L5.docx).

Para que otros equipos puedan hacer ping a esta laptop (Windows bloquea ICMP por defecto), ejecuta PowerShell como administrador:

```powershell
New-NetFirewallRule -DisplayName "Permitir ping (practica IS-803)" -Protocol ICMPv4 -IcmpType 8 -Direction Inbound -Action Allow
```

Comandos individuales (desde la carpeta del proyecto, con `docker compose --profile pruebas up -d` activo):

| N.º | Evidencia | Comando | Qué debe verse |
|---|---|---|---|
| 1 | IP del nodo | `docker exec l5-files hostname -i` y `docker network inspect red_nodos` | `192.168.10.15` |
| 2 | Ping L3 → L5 | `docker exec l3-sim ping -c 4 192.168.10.15` | 0 % de pérdida |
| 2 | Ping L1 → L5 | `docker exec l1-sim ping -c 4 192.168.10.15` | 0 % de pérdida |
| 3 | Servicio activo | `docker compose ps` y `docker logs l5-files` | `Up (healthy)`, escuchando en `0.0.0.0:8080` |
| 4 | Acceso desde otro nodo | `docker exec l3-sim curl http://192.168.10.15:8080/health` | JSON con `request_from: 192.168.10.13` |
| 4 | Carga L3 → L5 | `docker exec l3-sim curl -H "x-api-key: CLAVE" -F "file=@/tmp/t.pdf;type=application/pdf" "http://192.168.10.15:8080/files?folder=payments"` | `201` con `storage_key` |
| 4 | Descarga L1 → L5 | `docker exec l1-sim curl -I http://192.168.10.15:8080/files/<storage_key>` | `200`, `Content-Type: application/pdf` |
| 4 | Desde otro computador | En otro PC de la misma red: `curl http://IP_LAN_DEL_ANFITRION:8080/health` | JSON de health |

Para crear el PDF de prueba dentro de `l3-sim`: `docker exec l3-sim sh -c "printf '%%PDF-1.4 prueba' > /tmp/t.pdf"`.

> En Git Bash para Windows, antepón `MSYS_NO_PATHCONV=1` a los comandos `docker exec` que llevan rutas como `/tmp/...`.

Ejemplo de redacción bajo cada captura:

> **Prueba realizada:** consumo del endpoint `/health` del Nodo L5 desde el Nodo L3.
> **Origen:** 192.168.10.13 (l3-sim). **Destino:** 192.168.10.15:8080 (l5-files).
> **Resultado:** L5 respondió `200` con `status: ok` e identificó la IP de origen, por lo que el servicio de archivos es alcanzable desde el backend.

## Flechas de L5 en la arquitectura

| Flecha | Quién llama | Medio / puerto | Para qué | Si L5 no está disponible |
|---|---|---|---|---|
| L3 → L5 | Backend NestJS | HTTP :8080, `POST/DELETE /files` con `x-api-key` | Guardar o eliminar archivos | La carga falla; L3 debe responder un error (p. ej. 503) y **no** registrar metadatos en L4 |
| L3 → L5 | Backend NestJS | HTTP :8080, `GET /files/...` | Leer un archivo | La operación con archivos falla; el resto de la API sigue funcionando |
| L1 → L5 | NGINX | Proxy HTTP :8080, `GET /files/...` | Servir archivos al navegador | NGINX devuelve `502/504`; las imágenes no cargan, pero el frontend y la API siguen activos |

El diseño **no** incluye replicación ni alta disponibilidad para L5. Es un punto único para las operaciones con archivos.
