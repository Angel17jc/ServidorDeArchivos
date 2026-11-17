// Demostración de interoperabilidad contra el L5 en ejecución (IS-803 · Tema 2.3).
// Se ejecuta desde otro nodo, por ejemplo el contenedor l3-sim:
//   docker exec -i -e CASO=P1 l3-sim node - < l5-file-server/scripts/demo-tema-2-3.js
// CASO: P1 (origen A), P2 (origen B equivalente) o P3 (errores). Datos ficticios.
'use strict';

const fs = require('fs');

const L5 = process.env.L5_URL || 'http://192.168.10.15:8080';
const CLAVE = process.env.FILES_API_KEY || 'clave-prueba-l5';
const CASO = (process.env.CASO || 'P1').toUpperCase();
const RESULTADO_P1 = '/tmp/contrato-p1.json';

// JPEG mínimo de 1x1 píxel, presentado como la foto de un producto
const JPEG = Buffer.from(
  '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=',
  'base64',
);

const json = (v) => JSON.stringify(v, null, 2);
const titulo = (t) => console.log(`\n--- ${t} ---`);

async function postExterno(cuerpo) {
  const res = await fetch(`${L5}/integraciones/almacen-externo`, {
    method: 'POST',
    headers: { 'x-api-key': CLAVE, 'content-type': 'application/json' },
    body: JSON.stringify(cuerpo),
  });
  return { status: res.status, cuerpo: await res.json() };
}

async function casoP1() {
  titulo('ENTRADA · origen A (POST /upload, multipart/form-data, campo "file")');
  console.log(`file = "camisa azul.jpg"  ·  tipo image/jpeg  ·  ${JPEG.length} bytes`);
  console.log('L5 la guarda con Multer; el descriptor de Multer es el origen A que traduce desdeAlmacenLocal()');

  const form = new FormData();
  form.append('file', new Blob([JPEG], { type: 'image/jpeg' }), 'camisa azul.jpg');
  const res = await fetch(`${L5}/upload`, { method: 'POST', headers: { 'x-api-key': CLAVE }, body: form });
  const contrato = await res.json();

  titulo(`SALIDA · contrato común (HTTP ${res.status})`);
  console.log(json(contrato));
  fs.writeFileSync(RESULTADO_P1, JSON.stringify(contrato));
  return contrato;
}

async function casoP2() {
  const p1 = fs.existsSync(RESULTADO_P1) ? JSON.parse(fs.readFileSync(RESULTADO_P1, 'utf8')) : await casoP1();

  // El mismo archivo descrito por el servicio de almacenamiento externo (formato S3/MinIO)
  const entradaB = {
    Bucket: 'tienda-archivos',
    Key: `productos/${p1.storage_key}`,
    ContentType: 'IMAGE/JPEG; charset=binary',
    ContentLength: String(p1.size),
    ETag: '"0d2f4d0c3f4c6f5b2a1e9b8c7d6e5f40"',
    LastModified: 'Tue, 17 Nov 2026 15:30:00 GMT',
    Location: `http://almacen-externo.test:9000/tienda-archivos/productos/${p1.storage_key}`,
    Metadata: { 'nombre-original': encodeURIComponent(p1.original_name) },
  };
  titulo('ENTRADA · origen B (POST /integraciones/almacen-externo)');
  console.log(json(entradaB));

  const { status, cuerpo } = await postExterno(entradaB);
  titulo(`SALIDA · contrato común (HTTP ${status})`);
  console.log(json(cuerpo));

  titulo('COMPARACIÓN P1 (origen A) vs P2 (origen B)');
  for (const campo of Object.keys(p1)) {
    const igual = JSON.stringify(p1[campo]) === JSON.stringify(cuerpo[campo]);
    console.log(`${igual ? 'OK ' : 'DIF'}  ${campo.padEnd(13)} ${JSON.stringify(p1[campo])} (${typeof p1[campo]})  |  ${JSON.stringify(cuerpo[campo])} (${typeof cuerpo[campo]})`);
  }
  const equivalentes = JSON.stringify(p1) === JSON.stringify(cuerpo);
  console.log(`\nResultado: ${equivalentes ? 'EQUIVALENTES, mismo significado y mismos tipos' : 'NO equivalentes'}`);
}

async function casoP3() {
  const casos = [
    ['Tamaño inválido: ContentLength con unidades', {
      Key: 'productos/8f14e45f-ceea-4e7a-9a0b-1f3c2b9d0a11.jpg', ContentType: 'image/jpeg',
      ContentLength: '48,2 KB', Metadata: { 'nombre-original': 'camisa%20azul.jpg' },
    }],
    ['Campo faltante: sin Metadata ni ContentType', {
      Key: 'productos/8f14e45f-ceea-4e7a-9a0b-1f3c2b9d0a11.jpg', ContentLength: '48213',
    }],
    ['Entrada ambigua: image/png con extensión .jpg', {
      Key: 'productos/8f14e45f-ceea-4e7a-9a0b-1f3c2b9d0a11.jpg', ContentType: 'image/png',
      ContentLength: '48213', Metadata: { 'nombre-original': 'camisa%20azul.jpg' },
    }],
  ];
  for (const [nombre, entrada] of casos) {
    titulo(`ENTRADA · ${nombre}`);
    console.log(JSON.stringify(entrada));
    const { status, cuerpo } = await postExterno(entrada);
    titulo(`SALIDA · HTTP ${status}`);
    console.log(json(cuerpo));
  }
}

const casos = { P1: casoP1, P2: casoP2, P3: casoP3 };
if (!casos[CASO]) {
  console.error(`CASO desconocido "${CASO}". Use P1, P2 o P3.`);
  process.exit(1);
}
casos[CASO]().catch((err) => {
  console.error(`No se pudo contactar a L5 en ${L5}: ${err.message}`);
  process.exit(1);
});
