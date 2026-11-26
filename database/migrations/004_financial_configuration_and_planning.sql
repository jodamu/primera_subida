CREATE TABLE IF NOT EXISTS financial_space_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope ENUM('personal', 'household') NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    household_id BIGINT UNSIGNED NULL,
    space_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(owner_user_id, household_id)) STORED,
    month_start_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
    budget_alert_percent TINYINT UNSIGNED NOT NULL DEFAULT 80,
    reminders_enabled TINYINT(1) NOT NULL DEFAULT 0,
    reminder_days_before TINYINT UNSIGNED NOT NULL DEFAULT 3,
    timezone VARCHAR(64) NOT NULL DEFAULT 'America/Bogota',
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_financial_settings_space (scope, space_key),
    CONSTRAINT fk_financial_settings_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
    CONSTRAINT fk_financial_settings_household FOREIGN KEY (household_id) REFERENCES households (id),
    CONSTRAINT fk_financial_settings_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_financial_settings_updater FOREIGN KEY (updated_by) REFERENCES users (id),
    CONSTRAINT chk_financial_settings_scope CHECK (
        (scope = 'personal' AND owner_user_id IS NOT NULL AND household_id IS NULL)
        OR
        (scope = 'household' AND owner_user_id IS NULL AND household_id IS NOT NULL)
    ),
    CONSTRAINT chk_financial_settings_period CHECK (month_start_day BETWEEN 1 AND 28),
    CONSTRAINT chk_financial_settings_alert CHECK (budget_alert_percent BETWEEN 1 AND 100),
    CONSTRAINT chk_financial_settings_reminder_days CHECK (reminder_days_before BETWEEN 0 AND 30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS budgets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope ENUM('personal', 'household') NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    household_id BIGINT UNSIGNED NULL,
    space_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(owner_user_id, household_id)) STORED,
    category_id SMALLINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    amount_limit DECIMAL(15,2) UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_budgets_space_category_period (scope, space_key, category_id, period_start),
    KEY idx_budgets_space_period (scope, space_key, period_start, period_end),
    CONSTRAINT fk_budgets_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
    CONSTRAINT fk_budgets_household FOREIGN KEY (household_id) REFERENCES households (id),
    CONSTRAINT fk_budgets_category FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT fk_budgets_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_budgets_updater FOREIGN KEY (updated_by) REFERENCES users (id),
    CONSTRAINT chk_budgets_scope CHECK (
        (scope = 'personal' AND owner_user_id IS NOT NULL AND household_id IS NULL)
        OR
        (scope = 'household' AND owner_user_id IS NULL AND household_id IS NOT NULL)
    ),
    CONSTRAINT chk_budgets_period CHECK (period_end >= period_start),
    CONSTRAINT chk_budgets_limit CHECK (amount_limit > 0.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS savings_goals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope ENUM('personal', 'household') NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    household_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    target_amount DECIMAL(15,2) UNSIGNED NOT NULL,
    target_date DATE NULL,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_savings_goals_owner_status (owner_user_id, status, target_date),
    KEY idx_savings_goals_household_status (household_id, status, target_date),
    CONSTRAINT fk_savings_goals_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
    CONSTRAINT fk_savings_goals_household FOREIGN KEY (household_id) REFERENCES households (id),
    CONSTRAINT fk_savings_goals_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_savings_goals_scope CHECK (
        (scope = 'personal' AND owner_user_id IS NOT NULL AND household_id IS NULL)
        OR
        (scope = 'household' AND owner_user_id IS NULL AND household_id IS NOT NULL)
    ),
    CONSTRAINT chk_savings_goals_target CHECK (target_amount > 0.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS savings_goal_entries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    goal_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    direction ENUM('contribution', 'withdrawal') NOT NULL,
    amount DECIMAL(15,2) UNSIGNED NOT NULL,
    effective_date DATE NOT NULL,
    description VARCHAR(255) NOT NULL,
    idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_savings_goal_entries_idempotency (created_by, idempotency_key),
    KEY idx_savings_goal_entries_goal_date (goal_id, effective_date, id),
    CONSTRAINT fk_savings_goal_entries_goal FOREIGN KEY (goal_id) REFERENCES savings_goals (id),
    CONSTRAINT fk_savings_goal_entries_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_savings_goal_entries_amount CHECK (amount > 0.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE operations
    ADD COLUMN import_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER idempotency_key,
    ADD UNIQUE KEY uq_operations_import_fingerprint (created_by, import_fingerprint);

CREATE TABLE IF NOT EXISTS csv_import_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    status ENUM('preview', 'confirmed', 'cancelled') NOT NULL DEFAULT 'preview',
    row_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_csv_import_batches_user_date (created_by, created_at),
    CONSTRAINT fk_csv_import_batches_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_csv_import_batches_account FOREIGN KEY (account_id) REFERENCES accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS csv_import_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    line_number SMALLINT UNSIGNED NOT NULL,
    operation_type ENUM('income', 'expense') NOT NULL,
    category_id SMALLINT UNSIGNED NOT NULL,
    effective_date DATE NOT NULL,
    amount DECIMAL(15,2) UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    import_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_duplicate TINYINT(1) NOT NULL DEFAULT 0,
    operation_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_csv_import_rows_line (batch_id, line_number),
    KEY idx_csv_import_rows_batch_duplicate (batch_id, is_duplicate),
    CONSTRAINT fk_csv_import_rows_batch FOREIGN KEY (batch_id) REFERENCES csv_import_batches (id),
    CONSTRAINT fk_csv_import_rows_category FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT fk_csv_import_rows_operation FOREIGN KEY (operation_id) REFERENCES operations (id),
    CONSTRAINT chk_csv_import_rows_amount CHECK (amount > 0.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS reminder_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope ENUM('personal', 'household') NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    household_id BIGINT UNSIGNED NULL,
    space_key BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(owner_user_id, household_id)) STORED,
    recipient_user_id BIGINT UNSIGNED NOT NULL,
    reference_type ENUM('budget_threshold', 'savings_goal_deadline') NOT NULL,
    reference_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_reminder_deliveries_once (
        scope, space_key, recipient_user_id, reference_type, reference_id, period_start
    ),
    CONSTRAINT fk_reminder_deliveries_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
    CONSTRAINT fk_reminder_deliveries_household FOREIGN KEY (household_id) REFERENCES households (id),
    CONSTRAINT fk_reminder_deliveries_recipient FOREIGN KEY (recipient_user_id) REFERENCES users (id),
    CONSTRAINT chk_reminder_deliveries_scope CHECK (
        (scope = 'personal' AND owner_user_id IS NOT NULL AND household_id IS NULL)
        OR
        (scope = 'household' AND owner_user_id IS NULL AND household_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
