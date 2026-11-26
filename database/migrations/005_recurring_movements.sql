CREATE TABLE IF NOT EXISTS recurring_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope ENUM('personal', 'household') NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    household_id BIGINT UNSIGNED NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    operation_type ENUM('income', 'expense') NOT NULL,
    category_id SMALLINT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(15,2) UNSIGNED NOT NULL,
    frequency ENUM('weekly', 'monthly', 'yearly') NOT NULL,
    interval_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
    anchor_day TINYINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    next_due_date DATE NOT NULL,
    end_date DATE NULL,
    status ENUM('active', 'paused', 'completed', 'archived') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_recurring_movements_owner_status (owner_user_id, status, next_due_date),
    KEY idx_recurring_movements_household_status (household_id, status, next_due_date),
    KEY idx_recurring_movements_account (account_id, status),
    CONSTRAINT fk_recurring_movements_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
    CONSTRAINT fk_recurring_movements_household FOREIGN KEY (household_id) REFERENCES households (id),
    CONSTRAINT fk_recurring_movements_account FOREIGN KEY (account_id) REFERENCES accounts (id),
    CONSTRAINT fk_recurring_movements_category FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT fk_recurring_movements_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_recurring_movements_scope CHECK (
        (scope = 'personal' AND owner_user_id IS NOT NULL AND household_id IS NULL)
        OR
        (scope = 'household' AND owner_user_id IS NULL AND household_id IS NOT NULL)
    ),
    CONSTRAINT chk_recurring_movements_amount CHECK (amount > 0.00),
    CONSTRAINT chk_recurring_movements_interval CHECK (interval_count BETWEEN 1 AND 12),
    CONSTRAINT chk_recurring_movements_anchor_day CHECK (anchor_day BETWEEN 1 AND 31),
    CONSTRAINT chk_recurring_movements_end_date CHECK (end_date IS NULL OR end_date >= start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS recurring_movement_occurrences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id BIGINT UNSIGNED NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('pending', 'confirmed') NOT NULL DEFAULT 'pending',
    operation_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    confirmed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_recurring_occurrences_schedule_due (schedule_id, due_date),
    UNIQUE KEY uq_recurring_occurrences_operation (operation_id),
    CONSTRAINT fk_recurring_occurrences_schedule FOREIGN KEY (schedule_id) REFERENCES recurring_movements (id),
    CONSTRAINT fk_recurring_occurrences_operation FOREIGN KEY (operation_id) REFERENCES operations (id),
    CONSTRAINT fk_recurring_occurrences_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_recurring_occurrences_confirmer FOREIGN KEY (confirmed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
