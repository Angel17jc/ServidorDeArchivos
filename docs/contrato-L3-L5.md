# Contrato L3 (módulo `files` de NestJS) ↔ L5 (servidor de archivos)

## Configuración en L3

```env
FILES_SERVICE_URL=http://192.168.10.15:8080   # o http://IP_LAN_DEL_EQUIPO_L5:8080
FILES_API_KEY=la-misma-clave-que-en-L5
```

## Flujo de carga (sección 6.3 del documento de arquitectura)

1. El cliente envía `multipart/form-data` a `POST /api/files` (L1 → L3).
2. L3 valida el JWT y los roles.
3. L3 reenvía el archivo a `POST {FILES_SERVICE_URL}/files?folder=products` con la cabecera `x-api-key`.
4. L5 responde `201`:
   ```json
   {
     "storage_key": "products/2f1c...e9.jpg",
     "url": "http://192.168.10.11/files/products/2f1c...e9.jpg",
     "original_name": "foto.jpg",
     "mime_type": "image/jpeg",
     "size": 48213,
     "stored_at": "2026-09-29T18:30:28.195Z"
   }
   ```
5. L3 guarda los metadatos en L4. Si falla el guardado, debe llamar a `DELETE /files/{storage_key}` para no dejar archivos huérfanos.
6. L3 responde al cliente por L1.

## Tabla sugerida en L4 (PostgreSQL)

```sql
CREATE TABLE files (
  id            SERIAL PRIMARY KEY,
  storage_key   VARCHAR(255) NOT NULL UNIQUE,
  original_name VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(100) NOT NULL,
  size          INTEGER      NOT NULL,
  entity_type   VARCHAR(50),          -- 'product', 'payment', 'user', ...
  entity_id     INTEGER,
  uploaded_by   INTEGER,              -- id del usuario
  created_at    TIMESTAMP    NOT NULL DEFAULT NOW()
);
```

## Ejemplo en el servicio de NestJS (Node 18+, sin librerías extra)

```ts
async uploadToL5(file: Express.Multer.File, folder: string) {
  const form = new FormData();
  form.append('file', new Blob([file.buffer], { type: file.mimetype }), file.originalname);

  const res = await fetch(`${process.env.FILES_SERVICE_URL}/files?folder=${folder}`, {
    method: 'POST',
    headers: { 'x-api-key': process.env.FILES_API_KEY },
    body: form,
  });
  if (!res.ok) throw new ServiceUnavailableException(`L5 respondió ${res.status}`);
  return res.json(); // { storage_key, url, original_name, mime_type, size }
}
```

## Códigos de respuesta de L5

| Código | Significado |
|---|---|
| 201 | Archivo guardado |
| 204 | Archivo eliminado |
| 400 | Falta `file` o la carpeta no es válida |
| 401 | Falta `x-api-key` o es incorrecta |
| 404 | No existe el archivo |
| 413 | Supera el tamaño máximo (10 MB) |
| 415 | Tipo no permitido (solo JPG, PNG, WEBP y PDF) |
