ALTER TABLE liabilities
    ADD COLUMN planned_monthly_payment DECIMAL(15,2) UNSIGNED NULL AFTER annual_interest_rate;

CREATE TABLE IF NOT EXISTS reconciliation_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    status ENUM('preview', 'reconciled', 'cancelled') NOT NULL DEFAULT 'preview',
    row_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_reconciliation_batches_owner_created (created_by, created_at),
    CONSTRAINT fk_reconciliation_batches_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_reconciliation_batches_account FOREIGN KEY (account_id) REFERENCES accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS reconciliation_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    line_number SMALLINT UNSIGNED NOT NULL,
    transaction_date DATE NOT NULL,
    operation_type ENUM('income', 'expense') NOT NULL,
    amount DECIMAL(15,2) UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    candidate_operation_id BIGINT UNSIGNED NULL,
    matched_operation_id BIGINT UNSIGNED NULL,
    status ENUM('pending', 'matched', 'unmatched', 'ignored') NOT NULL DEFAULT 'pending',
    PRIMARY KEY (id),
    UNIQUE KEY uq_reconciliation_rows_line (batch_id, line_number),
    KEY idx_reconciliation_rows_fingerprint (batch_id, fingerprint),
    UNIQUE KEY uq_reconciliation_rows_matched_operation (matched_operation_id),
    KEY idx_reconciliation_rows_batch_status (batch_id, status),
    CONSTRAINT fk_reconciliation_rows_batch FOREIGN KEY (batch_id) REFERENCES reconciliation_batches (id),
    CONSTRAINT fk_reconciliation_rows_candidate FOREIGN KEY (candidate_operation_id) REFERENCES operations (id),
    CONSTRAINT fk_reconciliation_rows_matched FOREIGN KEY (matched_operation_id) REFERENCES operations (id),
    CONSTRAINT chk_reconciliation_rows_amount CHECK (amount > 0.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS backup_restore_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by BIGINT UNSIGNED NOT NULL,
    fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('preview', 'restored', 'cancelled') NOT NULL DEFAULT 'preview',
    source_version TINYINT UNSIGNED NOT NULL,
    payload_json MEDIUMTEXT NULL,
    row_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_backup_restore_owner_fingerprint (created_by, fingerprint),
    KEY idx_backup_restore_owner_created (created_by, created_at),
    CONSTRAINT fk_backup_restore_creator FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS financial_backup_origin (
    origin_key TINYINT UNSIGNED NOT NULL,
    origin_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (origin_key),
    UNIQUE KEY uq_financial_backup_origin_id (origin_id),
    CONSTRAINT chk_financial_backup_origin_singleton CHECK (origin_key = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS backup_restore_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    restored_by BIGINT UNSIGNED NOT NULL,
    source_origin_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    record_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    target_scope ENUM('personal', 'household') NOT NULL,
    target_space_id BIGINT UNSIGNED NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_backup_restore_source_record (
        restored_by, source_origin_id, record_type, source_id, target_scope, target_space_id
    ),
    CONSTRAINT fk_backup_restore_record_user FOREIGN KEY (restored_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
