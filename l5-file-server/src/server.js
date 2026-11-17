// Nodo L5 - Servidor de archivos
// Almacena y sirve archivos binarios. Las cargas llegan desde el backend (L3);
// las descargas pueden llegar desde L3 o directamente desde NGINX (L1) por /files/.
const express = require('express');
const multer = require('multer');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const os = require('os');
const { NormalizadorArchivos, ErrorValidacion } = require('./services/NormalizadorArchivos');

const PORT = Number(process.env.PORT) || 8080;
const HOST = process.env.HOST || '0.0.0.0'; // escuchar en todas las interfaces, no solo en localhost
const STORAGE_DIR = path.resolve(process.env.STORAGE_DIR || path.join(__dirname, '..', 'storage'));
const TMP_DIR = path.join(STORAGE_DIR, '.tmp');
const API_KEY = process.env.FILES_API_KEY || '';
const MAX_FILE_SIZE_MB = Number(process.env.MAX_FILE_SIZE_MB) || 10;
const PUBLIC_BASE_URL = (process.env.PUBLIC_BASE_URL || 'http://192.168.10.11/files').replace(/\/+$/, '');

// Carpetas lógicas según el objeto del dominio al que pertenece el archivo
const FOLDERS = ['products', 'payments', 'users', 'messages', 'general'];

// Tipos permitidos -> extensión con la que se guarda (no se confía en el nombre original)
const MIME_TYPES = {
  'image/jpeg': '.jpg',
  'image/png': '.png',
  'image/webp': '.webp',
  'application/pdf': '.pdf',
};

// storage_key = <carpeta>/<uuid><ext>
const KEY_NAME_RE = /^[0-9a-f-]{36}\.(jpg|png|webp|pdf)$/;

fs.mkdirSync(TMP_DIR, { recursive: true });
for (const folder of FOLDERS) fs.mkdirSync(path.join(STORAGE_DIR, folder), { recursive: true });

const app = express();
app.disable('x-powered-by');

function clientIp(req) {
  return (req.socket.remoteAddress || '').replace(/^::ffff:/, '');
}

// Quién hace la solicitud: NGINX añade X-Real-IP; el backend usa axios/fetch de Node; el navegador, Mozilla
function originOf(req) {
  const ua = req.get('user-agent') || '';
  if (req.get('x-real-ip') || req.get('x-forwarded-for')) return 'NGINX (L1)';
  if (/axios|node|undici|got|nestjs/i.test(ua)) return 'Backend (L3)';
  if (/curl/i.test(ua)) return 'curl';
  if (/mozilla/i.test(ua)) return 'Navegador';
  return 'Otro';
}

// Últimas solicitudes, para el panel /panel
const RECENT_MAX = 60;
const recent = [];

// Registro de cada solicitud con la IP de origen (útil como evidencia de conectividad)
app.use((req, res, next) => {
  const start = Date.now();
  if (req.path === '/health' && clientIp(req) === '127.0.0.1') return next(); // healthcheck interno de Docker
  if (req.path === '/panel') return next();
  res.on('finish', () => {
    const entry = {
      time: new Date(),
      ip: clientIp(req),
      realIp: req.get('x-real-ip') || req.get('x-forwarded-for') || '',
      origin: originOf(req),
      method: req.method,
      url: req.originalUrl,
      status: res.statusCode,
      ms: Date.now() - start,
    };
    recent.unshift(entry);
    if (recent.length > RECENT_MAX) recent.pop();
    console.log(`[L5] ${entry.time.toISOString()} ${entry.ip} ${entry.method} ${entry.url} -> ${entry.status} (${entry.ms} ms) [${entry.origin}]`);
  });
  next();
});

// Operaciones de escritura: solo el backend (L3), que conoce la clave compartida
function requireApiKey(req, res, next) {
  if (!API_KEY) return next();
  const provided = req.get('x-api-key') || '';
  const ok = provided.length === API_KEY.length &&
    crypto.timingSafeEqual(Buffer.from(provided), Buffer.from(API_KEY));
  if (!ok) return res.status(401).json({ error: 'API key inválida o ausente' });
  next();
}

// folder vacío = archivo en la raíz del almacenamiento (claves simples de POST /upload)
function resolveKey(folder, name) {
  if (!KEY_NAME_RE.test(name)) return null;
  if (folder === undefined) return path.join(STORAGE_DIR, name);
  if (!FOLDERS.includes(folder)) return null;
  return path.join(STORAGE_DIR, folder, name);
}

function fileFilter(req, file, cb) {
  if (MIME_TYPES[file.mimetype]) return cb(null, true);
  const err = new Error(`Tipo de archivo no permitido: ${file.mimetype}`);
  err.status = 415;
  cb(err);
}
const limits = { fileSize: MAX_FILE_SIZE_MB * 1024 * 1024, files: 1 };

const upload = multer({ dest: TMP_DIR, limits, fileFilter });

// POST /upload guarda directamente con el nombre definitivo <uuid>.<ext>
const uploadLocal = multer({
  storage: multer.diskStorage({
    destination: STORAGE_DIR,
    filename: (req, file, cb) => cb(null, crypto.randomUUID() + MIME_TYPES[file.mimetype]),
  }),
  limits,
  fileFilter,
});

// Traductor al contrato común que consume el backend (L3)
const normalizador = new NormalizadorArchivos({ limiteBytes: limits.fileSize });

app.get('/', (req, res) => {
  res.json({
    node: 'L5',
    service: 'file-server',
    endpoints: ['GET /health', 'GET /panel', 'POST /upload', 'POST /integraciones/almacen-externo', 'GET /files/:name', 'DELETE /files/:name', 'POST /files', 'GET /files/:folder/:name', 'DELETE /files/:folder/:name'],
  });
});

app.get('/health', (req, res) => {
  let writable = true;
  try {
    fs.accessSync(STORAGE_DIR, fs.constants.W_OK);
  } catch {
    writable = false;
  }
  res.status(writable ? 200 : 503).json({
    status: writable ? 'ok' : 'error',
    node: 'L5',
    service: 'file-server',
    hostname: os.hostname(),
    storage_writable: writable,
    uptime_s: Math.round(process.uptime()),
    request_from: clientIp(req),
    timestamp: new Date().toISOString(),
  });
});

// Panel en vivo para mostrar las conexiones al nodo L5 (se refresca solo cada 3 s)
const esc = (v) => String(v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

app.get('/panel', async (req, res) => {
  const files = [];
  for (const dir of ['', ...FOLDERS]) {
    let names = [];
    try { names = await fs.promises.readdir(path.join(STORAGE_DIR, dir)); } catch {}
    for (const name of names) {
      if (!KEY_NAME_RE.test(name)) continue;
      const st = await fs.promises.stat(path.join(STORAGE_DIR, dir, name));
      files.push({ key: dir ? `${dir}/${name}` : name, size: st.size, mtime: st.mtime });
    }
  }
  files.sort((a, b) => b.mtime - a.mtime);

  const fmtTime = (d) => d.toLocaleTimeString('es-EC', { timeZone: 'America/Guayaquil', hour12: false });
  const byOrigin = {};
  for (const r of recent) byOrigin[r.origin] = r;

  const statusColor = (c) => (c < 300 ? '#15803d' : c < 400 ? '#a16207' : '#b91c1c');
  const rows = recent.map((r) => `<tr>
      <td>${fmtTime(r.time)}</td><td><b>${esc(r.origin)}</b></td>
      <td><code>${esc(r.ip)}</code>${r.realIp ? `<br><small>cliente: ${esc(r.realIp)}</small>` : ''}</td>
      <td><code>${esc(r.method)} ${esc(r.url.slice(0, 70))}</code></td>
      <td style="color:${statusColor(r.status)};font-weight:700">${r.status}</td><td>${r.ms} ms</td></tr>`).join('');

  const cards = Object.values(byOrigin).map((r) =>
    `<div class="card"><div class="lbl">${esc(r.origin)}</div><div class="val">${fmtTime(r.time)}</div><small>${esc(r.method)} ${esc(r.url.slice(0, 40))} → ${r.status}</small></div>`).join('');

  const thumbs = files.slice(0, 12).map((f) => f.key.endsWith('.pdf')
    ? `<a class="thumb pdf" href="/files/${esc(f.key)}" target="_blank">PDF<small>${esc(f.key.slice(-12))}</small></a>`
    : `<a class="thumb" href="/files/${esc(f.key)}" target="_blank"><img src="/files/${esc(f.key)}" alt=""><small>${esc(f.key.slice(-12))}</small></a>`).join('');

  res.set('Cache-Control', 'no-store').type('html').send(`<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="3">
<title>Panel L5 · Servidor de archivos</title><style>
body{margin:0;font-family:Segoe UI,system-ui,sans-serif;background:#f8fafc;color:#1f2937}
header{background:#c2410c;color:#fff;padding:16px 24px}header h1{margin:0;font-size:22px}header p{margin:4px 0 0;opacity:.9}
main{padding:20px 24px;max-width:1200px}h2{font-size:16px;margin:24px 0 10px}
.grid{display:flex;flex-wrap:wrap;gap:12px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px 16px;min-width:180px}
.lbl{font-size:12px;color:#6b7280;text-transform:uppercase;font-weight:700}.val{font-size:20px;font-weight:700;margin:4px 0}
.ok{color:#15803d}table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;font-size:14px}
th,td{text-align:left;padding:7px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top}th{background:#f3f4f6}
.thumb{display:flex;flex-direction:column;align-items:center;width:110px;text-decoration:none;color:#6b7280;font-size:11px}
.thumb img{width:110px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;background:#fff}
.pdf{justify-content:center;height:80px;border:1px solid #e5e7eb;border-radius:6px;background:#fff;color:#b91c1c;font-weight:700}
.note{color:#6b7280;font-size:13px}code{font-family:Consolas,monospace;font-size:13px}
</style></head><body>
<header><h1>Nodo L5 · Servidor de archivos</h1>
<p>${esc(os.hostname())} · red Docker 192.168.10.15:${PORT} · en línea desde hace ${Math.round(process.uptime() / 60)} min · se actualiza cada 3 s</p></header>
<main>
<div class="grid">
  <div class="card"><div class="lbl">Estado</div><div class="val ok">● En línea</div><small>GET / y /health responden 200</small></div>
  <div class="card"><div class="lbl">Archivos guardados</div><div class="val">${files.length}</div><small>en /srv/storage</small></div>
  <div class="card"><div class="lbl">Solicitudes registradas</div><div class="val">${recent.length}</div><small>últimas ${RECENT_MAX}</small></div>
</div>
<h2>Última conexión de cada nodo</h2>
<div class="grid">${cards || '<p class="note">Aún no hay solicitudes.</p>'}</div>
<h2>Últimas imágenes recibidas</h2>
<div class="grid">${thumbs || '<p class="note">Aún no se han subido archivos.</p>'}</div>
<h2>Solicitudes recibidas</h2>
<table><tr><th>Hora</th><th>Origen</th><th>IP</th><th>Solicitud</th><th>Código</th><th>Tiempo</th></tr>${rows}</table>
<p class="note">El origen se identifica por la cabecera X-Real-IP que añade NGINX (L1) y por el cliente HTTP del backend (L3).
En Docker Desktop, las conexiones que llegan desde otros equipos por el puerto publicado aparecen con la IP de la puerta de enlace 192.168.10.1.</p>
</main></body></html>`);
});

// Carga en el formato acordado con L3: multipart/form-data, campo "file".
// Origen A: el descriptor de Multer se traduce al contrato común
// { storage_key, url, original_name, mime_type, size }; NGINX (L1) enruta /files/ hacia L5.
app.post('/upload', requireApiKey, uploadLocal.single('file'), (req, res) => {
  if (!req.file) return res.status(400).json({ error: 'Falta el campo "file"' });
  res.status(201).json(normalizador.desdeAlmacenLocal(req.file));
});

// Origen B: metadatos de un objeto del servicio de almacenamiento externo (simulado, formato S3/MinIO).
// Devuelve el mismo contrato común que POST /upload, para que L3 lo registre sin cambios.
app.post('/integraciones/almacen-externo', requireApiKey, express.json({ limit: '20kb' }), (req, res) => {
  res.json(normalizador.desdeAlmacenExterno(req.body));
});

// Carga: multipart/form-data con el campo "file"; carpeta opcional por ?folder= o campo "folder"
app.post('/files', requireApiKey, upload.single('file'), async (req, res, next) => {
  try {
    if (!req.file) return res.status(400).json({ error: 'Falta el campo "file"' });

    const folder = req.query.folder || req.body.folder || 'general';
    if (!FOLDERS.includes(folder)) {
      await fs.promises.rm(req.file.path, { force: true });
      return res.status(400).json({ error: `Carpeta no válida. Use: ${FOLDERS.join(', ')}` });
    }

    const name = crypto.randomUUID() + MIME_TYPES[req.file.mimetype];
    const storageKey = `${folder}/${name}`;
    await fs.promises.rename(req.file.path, path.join(STORAGE_DIR, folder, name));

    res.status(201).json({
      storage_key: storageKey,
      url: `${PUBLIC_BASE_URL}/${storageKey}`,
      original_name: req.file.originalname,
      mime_type: req.file.mimetype,
      size: req.file.size,
      stored_at: new Date().toISOString(),
    });
  } catch (err) {
    next(err);
  }
});

// Descarga (lectura pública, también accesible por L1 -> /files/)
function sendStored(req, res) {
  const filePath = resolveKey(req.params.folder, req.params.name);
  if (!filePath || !fs.existsSync(filePath)) return res.status(404).json({ error: 'Archivo no encontrado' });
  res.sendFile(filePath, { headers: { 'Cache-Control': 'public, max-age=3600', 'X-Content-Type-Options': 'nosniff' } });
}
app.get('/files/:name', sendStored);
app.get('/files/:folder/:name', sendStored);

// Eliminación (solo L3)
async function deleteStored(req, res) {
  const filePath = resolveKey(req.params.folder, req.params.name);
  if (!filePath || !fs.existsSync(filePath)) return res.status(404).json({ error: 'Archivo no encontrado' });
  await fs.promises.unlink(filePath);
  res.status(204).end();
}
app.delete('/files/:name', requireApiKey, deleteStored);
app.delete('/files/:folder/:name', requireApiKey, deleteStored);

app.use((req, res) => res.status(404).json({ error: 'Ruta no encontrada' }));

app.use((err, req, res, next) => {
  if (req.file) fs.promises.rm(req.file.path, { force: true }).catch(() => {});
  if (err instanceof ErrorValidacion) {
    return res.status(422).json({ error: 'Entrada no válida para el contrato común', origen: err.origen, detalles: err.detalles });
  }
  if (err.type === 'entity.parse.failed') {
    return res.status(400).json({ error: 'El cuerpo no es un JSON válido' });
  }
  if (err instanceof multer.MulterError) {
    const status = err.code === 'LIMIT_FILE_SIZE' ? 413 : 400;
    return res.status(status).json({ error: err.code === 'LIMIT_FILE_SIZE' ? `El archivo supera ${MAX_FILE_SIZE_MB} MB` : err.message });
  }
  console.error('[L5] Error:', err);
  res.status(err.status || 500).json({ error: err.status ? err.message : 'Error interno del servidor' });
});

if (require.main === module) {
  app.listen(PORT, HOST, () => {
    console.log(`[L5] Servidor de archivos escuchando en http://${HOST}:${PORT}`);
    console.log(`[L5] Almacenamiento: ${STORAGE_DIR} | Máx: ${MAX_FILE_SIZE_MB} MB | API key: ${API_KEY ? 'activada' : 'DESACTIVADA'}`);
  });
}

module.exports = app;
