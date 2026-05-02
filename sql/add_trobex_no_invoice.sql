-- Run once: separate SFTP upload flag for no-invoice / no-sales CSV segment.
ALTER TABLE tbl_sales
    ADD COLUMN trobex_no_invoice TINYINT(1) NOT NULL DEFAULT 0;
