// Pruebas del traductor NormalizadorArchivos (IS-803 · Tema 2.3)
'use strict';

const { describe, it } = require('node:test');
const assert = require('node:assert/strict');
const { NormalizadorArchivos, ErrorValidacion } = require('../src/services/NormalizadorArchivos');

const ORIGEN_A = require('./fixtures/origen-a-almacen-local.json');
const ORIGEN_B = require('./fixtures/origen-b-almacen-externo.json');
const CONTRATO = require('./fixtures/contrato-comun-esperado.json');

const normalizador = new NormalizadorArchivos();
const copia = (obj) => structuredClone(obj);

// Comprueba que se lanza ErrorValidacion y que señala el campo de origen indicado
function esperarError(fn, origen, campo, fragmentoMotivo) {
  assert.throws(fn, (err) => {
    assert.ok(err instanceof ErrorValidacion, `se esperaba ErrorValidacion y llegó ${err && err.name}`);
    assert.equal(err.origen, origen);
    const detalle = err.detalles.find((d) => d.campo === campo);
    assert.ok(detalle, `no hay error para "${campo}": ${JSON.stringify(err.detalles)}`);
    if (fragmentoMotivo) assert.match(detalle.motivo, fragmentoMotivo);
    return true;
  });
}

describe('P1 · Entrada A válida (almacenamiento local de L5, Multer)', () => {
  it('produce el contrato común correcto', () => {
    assert.deepEqual(normalizador.desdeAlmacenLocal(copia(ORIGEN_A)), CONTRATO);
  });

  it('entrega los tipos del contrato: textos y size numérico', () => {
    const c = normalizador.desdeAlmacenLocal(copia(ORIGEN_A));
    assert.deepEqual(Object.keys(c), ['storage_key', 'url', 'original_name', 'mime_type', 'size']);
    assert.equal(typeof c.size, 'number');
    assert.ok(Number.isInteger(c.size));
  });

  it('no modifica el objeto de origen', () => {
    const entrada = copia(ORIGEN_A);
    normalizador.desdeAlmacenLocal(entrada);
    assert.deepEqual(entrada, ORIGEN_A);
  });
});

describe('P2 · Entrada B equivalente (almacenamiento externo, formato S3/MinIO)', () => {
  it('produce el mismo contrato, con el mismo significado y tipos que P1', () => {
    const p1 = normalizador.desdeAlmacenLocal(copia(ORIGEN_A));
    const p2 = normalizador.desdeAlmacenExterno(copia(ORIGEN_B));
    assert.deepEqual(p2, p1);
    assert.deepEqual(p2, CONTRATO);
  });

  it('traduce nombres, formato de clave, mayúsculas del tipo, texto a número y nombre anidado', () => {
    const c = normalizador.desdeAlmacenExterno(copia(ORIGEN_B));
    assert.equal(c.storage_key, '8f14e45f-ceea-4e7a-9a0b-1f3c2b9d0a11.jpg'); // sin el prefijo "productos/"
    assert.equal(c.mime_type, 'image/jpeg'); // "IMAGE/JPEG; charset=binary"
    assert.equal(c.size, 48213); // "48213" (texto) -> 48213 (número)
    assert.equal(c.original_name, 'camisa azul.jpg'); // Metadata["nombre-original"] = "camisa%20azul.jpg"
  });

  it('acepta ContentLength como entero (SDK) además de texto (cabecera HTTP)', () => {
    const entrada = { ...copia(ORIGEN_B), ContentLength: 48213 };
    assert.deepEqual(normalizador.desdeAlmacenExterno(entrada), CONTRATO);
  });

  it('no modifica el objeto de origen', () => {
    const entrada = copia(ORIGEN_B);
    normalizador.desdeAlmacenExterno(entrada);
    assert.deepEqual(entrada, ORIGEN_B);
  });
});

describe('P3 · Campo faltante o valor inválido: error de validación explícito', () => {
  it('A: falta originalname', () => {
    const { originalname, ...entrada } = copia(ORIGEN_A);
    esperarError(() => normalizador.desdeAlmacenLocal(entrada), 'almacen-local', 'originalname', /obligatorio/);
  });

  it('A: size como texto no se convierte en silencio', () => {
    const entrada = { ...copia(ORIGEN_A), size: '48213' };
    esperarError(() => normalizador.desdeAlmacenLocal(entrada), 'almacen-local', 'size', /número/);
  });

  it('B: ContentLength con unidades ("48,2 KB") se rechaza', () => {
    const entrada = { ...copia(ORIGEN_B), ContentLength: '48,2 KB' };
    esperarError(() => normalizador.desdeAlmacenExterno(entrada), 'almacen-externo', 'ContentLength', /sin unidades ni separadores/);
  });

  it('B: ContentLength con separador de miles ("48.213") se rechaza por ambiguo', () => {
    const entrada = { ...copia(ORIGEN_B), ContentLength: '48.213' };
    esperarError(() => normalizador.desdeAlmacenExterno(entrada), 'almacen-externo', 'ContentLength');
  });

  it('B: falta el objeto anidado Metadata', () => {
    const { Metadata, ...entrada } = copia(ORIGEN_B);
    esperarError(() => normalizador.desdeAlmacenExterno(entrada), 'almacen-externo', 'Metadata', /nombre-original/);
  });

  it('B: nombre-original con codificación URL inválida', () => {
    const entrada = { ...copia(ORIGEN_B), Metadata: { 'nombre-original': 'camisa%E0%A4%A.jpg' } };
    esperarError(() => normalizador.desdeAlmacenExterno(entrada), 'almacen-externo', 'Metadata.nombre-original', /codificación/);
  });

  it('B: Key con ruta relativa ("../") se rechaza', () => {
    const entrada = { ...copia(ORIGEN_B), Key: '../etc/8f14e45f-ceea-4e7a-9a0b-1f3c2b9d0a11.jpg' };
    esperarError(() => normalizador.desdeAlmacenExterno(entrada), 'almacen-externo', 'Key', /formato no reconocido/);
  });

  it('B: tipo y extensión contradictorios (image/png con .jpg) es ambiguo', () => {
    const entrada = { ...copia(ORIGEN_B), ContentType: 'image/png' };
    esperarError(() => normalizador.desdeAlmacenExterno(entrada), 'almacen-externo', 'ContentType', /ambiguo/);
  });

  it('B: tipo no permitido (text/html)', () => {
    const entrada = { ...copia(ORIGEN_B), ContentType: 'text/html' };
    esperarError(() => normalizador.desdeAlmacenExterno(entrada), 'almacen-externo', 'ContentType', /no permitido/);
  });

  it('B: tamaño mayor al máximo de 10 MB', () => {
    const entrada = { ...copia(ORIGEN_B), ContentLength: String(11 * 1024 * 1024) };
    esperarError(() => normalizador.desdeAlmacenExterno(entrada), 'almacen-externo', 'ContentLength', /supera el máximo/);
  });

  it('entrada que no es un objeto', () => {
    esperarError(() => normalizador.desdeAlmacenLocal(null), 'almacen-local', '(entrada)');
    esperarError(() => normalizador.desdeAlmacenExterno([]), 'almacen-externo', '(entrada)');
  });

  it('informa todos los problemas a la vez, no solo el primero', () => {
    const entrada = { Key: 'foto.jpg', ContentType: '', ContentLength: '-5' };
    assert.throws(() => normalizador.desdeAlmacenExterno(entrada), (err) => {
      const campos = err.detalles.map((d) => d.campo).sort();
      assert.deepEqual(campos, ['ContentLength', 'ContentType', 'Key', 'Metadata']);
      return true;
    });
  });
});
