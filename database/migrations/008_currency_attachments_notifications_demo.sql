CREATE TABLE IF NOT EXISTS exchange_rates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope ENUM('personal', 'household') NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    household_id BIGINT UNSIGNED NULL,
    space_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(owner_user_id, household_id)) STORED,
    currency CHAR(3) NOT NULL,
    rate_to_cop DECIMAL(18,6) UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_exchange_rates_space_currency (scope, space_key, currency),
    CONSTRAINT fk_exchange_rates_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
    CONSTRAINT fk_exchange_rates_household FOREIGN KEY (household_id) REFERENCES households (id),
    CONSTRAINT fk_exchange_rates_updater FOREIGN KEY (updated_by) REFERENCES users (id),
    CONSTRAINT chk_exchange_rates_scope CHECK (
        (scope = 'personal' AND owner_user_id IS NOT NULL AND household_id IS NULL)
        OR
        (scope = 'household' AND owner_user_id IS NULL AND household_id IS NOT NULL)
    ),
    CONSTRAINT chk_exchange_rates_currency CHECK (currency IN ('USD', 'EUR')),
    CONSTRAINT chk_exchange_rates_rate CHECK (rate_to_cop > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS operation_attachments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_id BIGINT UNSIGNED NOT NULL,
    stored_name CHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_operation_attachments_stored (stored_name),
    KEY idx_operation_attachments_operation (operation_id, deleted_at),
    CONSTRAINT fk_operation_attachments_operation FOREIGN KEY (operation_id) REFERENCES operations (id),
    CONSTRAINT fk_operation_attachments_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id),
    CONSTRAINT chk_operation_attachments_size CHECK (size_bytes BETWEEN 1 AND 5242880)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE households
    ADD COLUMN is_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER name;

ALTER TABLE reminder_deliveries
    MODIFY COLUMN reference_type ENUM(
        'budget_threshold', 'savings_goal_deadline', 'weekly_summary',
        'liability_due', 'recurring_due'
    ) NOT NULL;
