-- HotelDatabase only, applied explicitly on its own PDO. No default application migration.
CREATE TABLE IF NOT EXISTS hotel_server_catalog_documents (
 receipt_sha256 CHAR(64) NOT NULL PRIMARY KEY,
 payload_sha256 CHAR(64) NOT NULL,
 document_bytes LONGBLOB NOT NULL
) ENGINE=InnoDB;
