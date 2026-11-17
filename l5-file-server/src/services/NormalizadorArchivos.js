// Traductor de metadatos de archivos al contrato común que consume el backend (L3).
// IS-803 · Tema 2.3 · Patrones de traducción y normalización · Práctica individual (Angel17jc)
//
// Dos componentes describen el mismo archivo con estructuras distintas:
//   Origen A: descriptor que genera Multer al guardar el archivo en el disco de L5.
//   Origen B: metadatos de un objeto en el servicio de almacenamiento externo
//             (simulado, con el formato de S3/MinIO).
// Ambos se traducen al mismo contrato común, sin modificar a ninguno de los dos:
//   { storage_key, url, original_name, mime_type, size }
'use strict';

const LIMITE_BYTES_POR_DEFECTO = 10 * 1024 * 1024;

// Tipos admitidos y la única extensión válida para cada uno
const EXTENSION_POR_MIME = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
  'application/pdf': 'pdf',
};

const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
const STORAGE_KEY_RE = new RegExp(`^${UUID}\\.(jpg|png|webp|pdf)$`);
// En el servicio externo la clave lleva un prefijo de carpeta opcional: "productos/<uuid>.jpg"
const CLAVE_EXTERNA_RE = new RegExp(`^(?:[a-z0-9-]+/)?(${UUID}\\.(?:jpg|png|webp|pdf))$`);
const CARACTERES_PROHIBIDOS_RE = /[\\/\u0000-\u001f]/;

class ErrorValidacion extends Error {
  /**
   * @param {string} origen   formato de origen que se estaba traduciendo
   * @param {{campo: string, motivo: string}[]} detalles  un elemento por cada problema encontrado
   */
  constructor(origen, detalles) {
    super(`Entrada no válida (${origen}): ` + detalles.map((d) => `${d.campo}: ${d.motivo}`).join('; '));
    this.name = 'ErrorValidacion';
    this.origen = origen;
    this.detalles = detalles;
  }
}

const esObjeto = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const mostrar = (v) => JSON.stringify(v);

// Texto obligatorio y no vacío; si falta, se registra el error y se devuelve undefined
function textoRequerido(valor, campo, errores) {
  if (valor === undefined || valor === null) {
    errores.push({ campo, motivo: 'campo obligatorio ausente' });
    return undefined;
  }
  if (typeof valor !== 'string' || valor.trim() === '') {
    errores.push({ campo, motivo: `debe ser un texto no vacío (recibido: ${mostrar(valor)})` });
    return undefined;
  }
  return valor.trim();
}

class NormalizadorArchivos {
  constructor({ limiteBytes = LIMITE_BYTES_POR_DEFECTO } = {}) {
    this.limiteBytes = limiteBytes;
  }

  /**
   * Origen A: descriptor de Multer.
   * { fieldname, originalname, encoding, mimetype, destination, filename, path, size }
   */
  desdeAlmacenLocal(entrada) {
    const origen = 'almacen-local';
    if (!esObjeto(entrada)) {
      throw new ErrorValidacion(origen, [{ campo: '(entrada)', motivo: 'se esperaba un objeto' }]);
    }
    const errores = [];
    const storageKey = textoRequerido(entrada.filename, 'filename', errores);
    const nombre = textoRequerido(entrada.originalname, 'originalname', errores);
    const mime = textoRequerido(entrada.mimetype, 'mimetype', errores);

    // Multer siempre entrega size como número; un texto indica una entrada mal formada
    let size;
    if (entrada.size === undefined || entrada.size === null) {
      errores.push({ campo: 'size', motivo: 'campo obligatorio ausente' });
    } else if (typeof entrada.size !== 'number') {
      errores.push({ campo: 'size', motivo: `debe ser un número de bytes (recibido: ${mostrar(entrada.size)})` });
    } else {
      size = entrada.size;
    }

    if (errores.length) throw new ErrorValidacion(origen, errores);
    return this.#construirContrato(origen, {
      storage_key: [storageKey, 'filename'],
      original_name: [nombre, 'originalname'],
      mime_type: [mime, 'mimetype'],
      size: [size, 'size'],
    });
  }

  /**
   * Origen B: metadatos del servicio de almacenamiento externo (formato S3/MinIO).
   * { Bucket, Key, ContentType, ContentLength, ETag, LastModified, Location,
   *   Metadata: { "nombre-original" } }
   */
  desdeAlmacenExterno(entrada) {
    const origen = 'almacen-externo';
    if (!esObjeto(entrada)) {
      throw new ErrorValidacion(origen, [{ campo: '(entrada)', motivo: 'se esperaba un objeto JSON' }]);
    }
    const errores = [];

    // Key: "<prefijo>/<uuid>.<ext>" -> storage_key "<uuid>.<ext>" (se descarta el prefijo de carpeta)
    let storageKey;
    const clave = textoRequerido(entrada.Key, 'Key', errores);
    if (clave !== undefined) {
      const coincidencia = CLAVE_EXTERNA_RE.exec(clave);
      if (coincidencia) storageKey = coincidencia[1];
      else errores.push({ campo: 'Key', motivo: `formato no reconocido ${mostrar(clave)}; se esperaba <prefijo>/<uuid>.<ext>` });
    }

    // ContentType: no distingue mayúsculas (RFC 9110) y puede traer parámetros ("; charset=binary")
    let mime;
    const tipo = textoRequerido(entrada.ContentType, 'ContentType', errores);
    if (tipo !== undefined) mime = tipo.split(';')[0].trim().toLowerCase();

    // ContentLength: bytes como texto de dígitos (cabecera HTTP) o como entero.
    // No se aceptan decimales, unidades ni separadores de miles: serían ambiguos.
    let size;
    const longitud = entrada.ContentLength;
    if (longitud === undefined || longitud === null) {
      errores.push({ campo: 'ContentLength', motivo: 'campo obligatorio ausente' });
    } else if (typeof longitud === 'string' && /^\d+$/.test(longitud.trim())) {
      size = Number(longitud.trim());
    } else if (typeof longitud === 'number' && Number.isInteger(longitud)) {
      size = longitud;
    } else {
      errores.push({ campo: 'ContentLength', motivo: `debe ser un entero de bytes sin unidades ni separadores (recibido: ${mostrar(longitud)})` });
    }

    // Metadata["nombre-original"]: anidado y codificado como URL (los metadatos de S3 solo admiten ASCII)
    let nombre;
    if (!esObjeto(entrada.Metadata)) {
      errores.push({ campo: 'Metadata', motivo: 'falta el objeto Metadata con "nombre-original"' });
    } else {
      const crudo = textoRequerido(entrada.Metadata['nombre-original'], 'Metadata.nombre-original', errores);
      if (crudo !== undefined) {
        try {
          nombre = decodeURIComponent(crudo);
        } catch {
          errores.push({ campo: 'Metadata.nombre-original', motivo: `codificación URL inválida ${mostrar(crudo)}` });
        }
      }
    }

    if (errores.length) throw new ErrorValidacion(origen, errores);
    return this.#construirContrato(origen, {
      storage_key: [storageKey, 'Key'],
      original_name: [nombre, 'Metadata.nombre-original'],
      mime_type: [mime, 'ContentType'],
      size: [size, 'ContentLength'],
    });
  }

  /**
   * Validación común del contrato, igual para todos los orígenes.
   * Cada valor viaja con el nombre del campo de origen para que el error indique qué corregir.
   */
  #construirContrato(origen, valores) {
    const [storageKey, campoClave] = valores.storage_key;
    const [nombre, campoNombre] = valores.original_name;
    const [mime, campoMime] = valores.mime_type;
    const [size, campoSize] = valores.size;
    const errores = [];

    const claveValida = STORAGE_KEY_RE.test(storageKey);
    if (!claveValida) {
      errores.push({ campo: campoClave, motivo: `debe ser <uuid>.<jpg|png|webp|pdf> (recibido: ${mostrar(storageKey)})` });
    }

    const extension = EXTENSION_POR_MIME[mime];
    if (!extension) {
      errores.push({ campo: campoMime, motivo: `tipo no permitido ${mostrar(mime)}; se aceptan ${Object.keys(EXTENSION_POR_MIME).join(', ')}` });
    } else if (claveValida && !storageKey.endsWith(`.${extension}`)) {
      errores.push({ campo: campoMime, motivo: `ambiguo: ${mime} no corresponde a la extensión de ${mostrar(storageKey)}` });
    }

    if (!Number.isSafeInteger(size) || size <= 0) {
      errores.push({ campo: campoSize, motivo: `debe ser un entero positivo de bytes (recibido: ${mostrar(size)})` });
    } else if (size > this.limiteBytes) {
      errores.push({ campo: campoSize, motivo: `supera el máximo de ${this.limiteBytes} bytes (recibido: ${size})` });
    }

    if (nombre.trim() === '') {
      errores.push({ campo: campoNombre, motivo: 'el nombre queda vacío después de decodificarlo' });
    } else if (nombre.length > 255) {
      errores.push({ campo: campoNombre, motivo: 'supera los 255 caracteres' });
    } else if (CARACTERES_PROHIBIDOS_RE.test(nombre)) {
      errores.push({ campo: campoNombre, motivo: `contiene barras o caracteres de control ${mostrar(nombre)}` });
    }

    if (errores.length) throw new ErrorValidacion(origen, errores);

    return {
      storage_key: storageKey,
      url: `/files/${storageKey}`,
      original_name: nombre.trim(),
      mime_type: mime,
      size,
    };
  }
}

module.exports = { NormalizadorArchivos, ErrorValidacion, EXTENSION_POR_MIME };
