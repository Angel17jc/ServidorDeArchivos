// Nodo L5 - Servidor de archivos
// Almacena y sirve archivos binarios. Las cargas llegan desde el backend (L3);
// las descargas pueden llegar desde L3 o directamente desde NGINX (L1) por /files/.
const express = require('express');
const multer = require('multer');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const os = require('os');

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

// Registro de cada solicitud con la IP de origen (útil como evidencia de conectividad)
app.use((req, res, next) => {
  const start = Date.now();
  if (req.path === '/health' && clientIp(req) === '127.0.0.1') return next(); // healthcheck interno de Docker
  res.on('finish', () => {
    console.log(`[L5] ${new Date().toISOString()} ${clientIp(req)} ${req.method} ${req.originalUrl} -> ${res.statusCode} (${Date.now() - start} ms)`);
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

function resolveKey(folder, name) {
  if (!FOLDERS.includes(folder) || !KEY_NAME_RE.test(name)) return null;
  return path.join(STORAGE_DIR, folder, name);
}

const upload = multer({
  dest: TMP_DIR,
  limits: { fileSize: MAX_FILE_SIZE_MB * 1024 * 1024, files: 1 },
  fileFilter: (req, file, cb) => {
    if (MIME_TYPES[file.mimetype]) return cb(null, true);
    const err = new Error(`Tipo de archivo no permitido: ${file.mimetype}`);
    err.status = 415;
    cb(err);
  },
});

app.get('/', (req, res) => {
  res.json({
    node: 'L5',
    service: 'file-server',
    endpoints: ['GET /health', 'POST /files', 'GET /files/:folder/:name', 'DELETE /files/:folder/:name'],
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
app.get('/files/:folder/:name', (req, res) => {
  const filePath = resolveKey(req.params.folder, req.params.name);
  if (!filePath || !fs.existsSync(filePath)) return res.status(404).json({ error: 'Archivo no encontrado' });
  res.sendFile(filePath, { headers: { 'Cache-Control': 'public, max-age=3600', 'X-Content-Type-Options': 'nosniff' } });
});

// Eliminación (solo L3)
app.delete('/files/:folder/:name', requireApiKey, async (req, res) => {
  const filePath = resolveKey(req.params.folder, req.params.name);
  if (!filePath || !fs.existsSync(filePath)) return res.status(404).json({ error: 'Archivo no encontrado' });
  await fs.promises.unlink(filePath);
  res.status(204).end();
});

app.use((req, res) => res.status(404).json({ error: 'Ruta no encontrada' }));

app.use((err, req, res, next) => {
  if (req.file) fs.promises.rm(req.file.path, { force: true }).catch(() => {});
  if (err instanceof multer.MulterError) {
    const status = err.code === 'LIMIT_FILE_SIZE' ? 413 : 400;
    return res.status(status).json({ error: err.code === 'LIMIT_FILE_SIZE' ? `El archivo supera ${MAX_FILE_SIZE_MB} MB` : err.message });
  }
  console.error('[L5] Error:', err);
  res.status(err.status || 500).json({ error: err.status ? err.message : 'Error interno del servidor' });
});

app.listen(PORT, HOST, () => {
  console.log(`[L5] Servidor de archivos escuchando en http://${HOST}:${PORT}`);
  console.log(`[L5] Almacenamiento: ${STORAGE_DIR} | Máx: ${MAX_FILE_SIZE_MB} MB | API key: ${API_KEY ? 'activada' : 'DESACTIVADA'}`);
});
