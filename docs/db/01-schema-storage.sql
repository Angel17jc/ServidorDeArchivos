-- =====================================================================
-- Schema "storage": metadatos de los archivos guardados en el nodo L5
-- Proyecto Integrador · Tienda en línea · Arquitectura de 5 nodos
--
-- Los archivos binarios viven en L5 (192.168.10.15:8080). Aquí solo se
-- guarda la información para localizarlos y relacionarlos con el dominio.
-- Quien escribe en esta tabla es el backend (L3, módulo files), nunca L5.
--
-- AJUSTAR CON L4 ANTES DE EJECUTAR:
--   * auth.users(id)  -> nombre real del schema/tabla de usuarios y tipo de PK
--   * app_backend     -> rol con el que se conecta el backend
-- =====================================================================

CREATE SCHEMA IF NOT EXISTS storage;
COMMENT ON SCHEMA storage IS
  'Metadatos de archivos binarios almacenados en el servidor de archivos L5 (192.168.10.15)';

CREATE TABLE IF NOT EXISTS storage.files (
    id            uuid         PRIMARY KEY DEFAULT gen_random_uuid(),
    storage_key   varchar(120) NOT NULL,                 -- clave devuelta por L5 en POST /upload: <uuid>.<ext>
    original_name varchar(255) NOT NULL,
    mime_type     varchar(50)  NOT NULL,
    size_bytes    integer      NOT NULL,
    uploaded_by   uuid         REFERENCES auth.users(id) ON DELETE SET NULL,
    created_at    timestamptz  NOT NULL DEFAULT now(),
    deleted_at    timestamptz,                           -- borrado lógico (el binario se elimina en L5)

    CONSTRAINT uq_files_storage_key UNIQUE (storage_key),
    CONSTRAINT ck_files_key_format  CHECK (storage_key ~ '^([a-z]+/)?[0-9a-f-]{36}\.(jpg|png|webp|pdf)$'),
    CONSTRAINT ck_files_mime_type   CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'application/pdf')),
    CONSTRAINT ck_files_size        CHECK (size_bytes > 0 AND size_bytes <= 10485760)  -- máx. 10 MB, igual que L5
);

CREATE INDEX IF NOT EXISTS ix_files_uploaded_by ON storage.files (uploaded_by);

COMMENT ON TABLE  storage.files             IS 'Un registro por archivo guardado en L5';
COMMENT ON COLUMN storage.files.storage_key IS 'Clave devuelta por L5 en POST /upload; se descarga con GET /files/<storage_key>';
COMMENT ON COLUMN storage.files.deleted_at  IS 'Fecha de borrado lógico; NULL si el archivo sigue vigente';

-- Permisos: el backend solo lee, registra y marca como borrado (sin DELETE físico)
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        GRANT USAGE ON SCHEMA storage TO app_backend;
        GRANT SELECT, INSERT, UPDATE ON storage.files TO app_backend;
    END IF;
END $$;
