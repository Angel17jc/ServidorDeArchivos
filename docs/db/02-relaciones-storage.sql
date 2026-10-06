-- =====================================================================
-- Relaciones de otros schemas con storage.files
-- Cada tabla vive en el schema del dominio que la usa y apunta a storage.files.
-- Ejecutar DESPUÉS de que existan catalog.products, sales.payments y
-- messaging.messages. AJUSTAR los nombres a los que haya definido L4.
-- =====================================================================

-- catalog: un producto tiene 0..N imágenes y como máximo una principal
CREATE TABLE IF NOT EXISTS catalog.product_images (
    product_id uuid     NOT NULL REFERENCES catalog.products(id) ON DELETE CASCADE,
    file_id    uuid     NOT NULL REFERENCES storage.files(id)    ON DELETE RESTRICT,
    position   smallint NOT NULL DEFAULT 1 CHECK (position > 0),
    is_main    boolean  NOT NULL DEFAULT false,
    PRIMARY KEY (product_id, file_id)
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_product_images_main
    ON catalog.product_images (product_id) WHERE is_main;

-- sales: un pago puede tener 0..1 comprobante (PDF o imagen)
ALTER TABLE sales.payments
    ADD COLUMN IF NOT EXISTS receipt_file_id uuid REFERENCES storage.files(id) ON DELETE RESTRICT;

-- messaging: un mensaje tiene 0..N adjuntos
CREATE TABLE IF NOT EXISTS messaging.message_attachments (
    message_id uuid NOT NULL REFERENCES messaging.messages(id) ON DELETE CASCADE,
    file_id    uuid NOT NULL REFERENCES storage.files(id)      ON DELETE RESTRICT,
    PRIMARY KEY (message_id, file_id)
);
