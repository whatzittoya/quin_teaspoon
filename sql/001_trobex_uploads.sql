-- QUINOS — per-category upload log.
--
-- tbl_sales.trobex is one bit per sale, and a single sale's lines can span
-- several departments, so it cannot record "bev uploaded, food not yet".
-- This table tracks upload state per (date, category) instead.
--
-- Apply with:
--   mysql -u root -p db_parklife < sql/001_trobex_uploads.sql

CREATE TABLE IF NOT EXISTS tbl_trobex_uploads (
    id           BIGINT       NOT NULL AUTO_INCREMENT,
    sale_date    DATE         NOT NULL,
    category_key VARCHAR(40)  NOT NULL,
    filename     VARCHAR(190) NOT NULL,
    rows_count   INT          NOT NULL DEFAULT 0,
    uploaded_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_date_category (sale_date, category_key),
    KEY idx_sale_date (sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
