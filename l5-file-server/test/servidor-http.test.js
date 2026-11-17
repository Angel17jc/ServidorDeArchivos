// Pruebas de integración HTTP: el traductor dentro del servidor L5 real (IS-803 · Tema 2.3)
'use strict';

const { describe, it, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');

// Servidor aislado: almacenamiento temporal y clave ficticia solo para las pruebas
const STORAGE = fs.mkdtempSync(path.join(os.tmpdir(), 'l5-pruebas-'));
process.env.STORAGE_DIR = STORAGE;
process.env.FILES_API_KEY = 'clave-ficticia-de-pruebas';
console.log = () => {}; // silencia el registro de solicitudes del servidor

const app = require('../src/server');

const CLAVE = { 'x-api-key': 'clave-ficticia-de-pruebas' };
// JPEG mínimo de 1x1 píxel (datos ficticios)
const JPEG = Buffer.from(
  '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=',
  'base64',
);

let servidor;
let base;
let contratoP1;

before(async () => {
  servidor = app.listen(0, '127.0.0.1');
  await new Promise((resolve) => servidor.once('listening', resolve));
  base = `http://127.0.0.1:${servidor.address().port}`;
});

after(() => {
  servidor.close();
  fs.rmSync(STORAGE, { recursive: true, force: true });
});

function subir(nombre, tipo, datos) {
  const form = new FormData();
  form.append('file', new Blob([datos], { type: tipo }), nombre);
  return fetch(`${base}/upload`, { method: 'POST', headers: CLAVE, body: form });
}

function enviarExterno(cuerpo) {
  return fetch(`${base}/integraciones/almacen-externo`, {
    method: 'POST',
    headers: { ...CLAVE, 'content-type': 'application/json' },
    body: typeof cuerpo === 'string' ? cuerpo : JSON.stringify(cuerpo),
  });
}

describe('HTTP · P1: POST /upload (origen A) responde el contrato común', () => {
  it('201 con exactamente los campos que consume el backend', async () => {
    const res = await subir('camisa azul.jpg', 'image/jpeg', JPEG);
    assert.equal(res.status, 201);
    contratoP1 = await res.json();
    assert.deepEqual(Object.keys(contratoP1), ['storage_key', 'url', 'original_name', 'mime_type', 'size']);
    assert.match(contratoP1.storage_key, /^[0-9a-f-]{36}\.jpg$/);
    assert.equal(contratoP1.url, `/files/${contratoP1.storage_key}`);
    assert.equal(contratoP1.original_name, 'camisa azul.jpg');
    assert.equal(contratoP1.mime_type, 'image/jpeg');
    assert.equal(contratoP1.size, JPEG.length);
  });

  it('el archivo queda disponible en la url del contrato', async () => {
    const res = await fetch(base + contratoP1.url);
    assert.equal(res.status, 200);
    assert.deepEqual(Buffer.from(await res.arrayBuffer()), JPEG);
  });
});

describe('HTTP · P2: POST /integraciones/almacen-externo (origen B) responde el mismo contrato', () => {
  it('los metadatos externos del mismo archivo producen un contrato idéntico al de P1', async () => {
    const res = await enviarExterno({
      Bucket: 'tienda-archivos',
      Key: `productos/${contratoP1.storage_key}`,
      ContentType: 'IMAGE/JPEG; charset=binary',
      ContentLength: String(JPEG.length),
      ETag: '"0d2f4d0c3f4c6f5b2a1e9b8c7d6e5f40"',
      LastModified: 'Tue, 17 Nov 2026 15:30:00 GMT',
      Metadata: { 'nombre-original': encodeURIComponent('camisa azul.jpg') },
    });
    assert.equal(res.status, 200);
    assert.deepEqual(await res.json(), contratoP1);
  });
});

describe('HTTP · P3: errores de validación explícitos', () => {
  it('422 con el detalle de cada campo inválido', async () => {
    const res = await enviarExterno({
      Key: `productos/${contratoP1.storage_key}`,
      ContentType: 'image/jpeg',
      ContentLength: '48,2 KB',
    });
    assert.equal(res.status, 422);
    const cuerpo = await res.json();
    assert.equal(cuerpo.origen, 'almacen-externo');
    assert.deepEqual(cuerpo.detalles.map((d) => d.campo).sort(), ['ContentLength', 'Metadata']);
  });

  it('400 cuando el cuerpo no es JSON válido', async () => {
    const res = await enviarExterno('{"Key": ');
    assert.equal(res.status, 400);
  });

  it('400 cuando POST /upload no trae el campo "file"', async () => {
    const res = await fetch(`${base}/upload`, { method: 'POST', headers: CLAVE, body: new FormData() });
    assert.equal(res.status, 400);
  });

  it('401 sin la cabecera x-api-key', async () => {
    const res = await fetch(`${base}/integraciones/almacen-externo`, { method: 'POST' });
    assert.equal(res.status, 401);
  });
});
