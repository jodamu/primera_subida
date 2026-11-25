ALTER TABLE users
    ADD COLUMN email_verified_at DATETIME NULL AFTER email,
    ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER status;

UPDATE users
SET email_verified_at = created_at
WHERE email_verified_at IS NULL;

ALTER TABLE operations
    MODIFY operation_type ENUM(
        'income',
        'expense',
        'transfer',
        'adjustment',
        'reversal',
        'liability_payment',
        'liability_disbursement'
    ) NOT NULL;

CREATE TABLE IF NOT EXISTS liabilities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind ENUM('credit_card', 'loan', 'other') NOT NULL,
    name VARCHAR(100) NOT NULL,
    creditor VARCHAR(120) NULL,
    scope ENUM('personal', 'household') NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    household_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    initial_balance DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0.00,
    initial_balance_date DATE NOT NULL,
    credit_limit DECIMAL(15,2) UNSIGNED NULL,
    statement_close_day TINYINT UNSIGNED NULL,
    payment_due_day TINYINT UNSIGNED NULL,
    annual_interest_rate DECIMAL(6,3) UNSIGNED NULL,
    currency CHAR(3) NOT NULL DEFAULT 'COP',
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_liabilities_owner_status (owner_user_id, status),
    KEY idx_liabilities_household_status (household_id, status),
    CONSTRAINT fk_liabilities_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
    CONSTRAINT fk_liabilities_household FOREIGN KEY (household_id) REFERENCES households (id),
    CONSTRAINT fk_liabilities_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_liabilities_scope CHECK (
        (scope = 'personal' AND owner_user_id IS NOT NULL AND household_id IS NULL)
        OR
        (scope = 'household' AND owner_user_id IS NULL AND household_id IS NOT NULL)
    ),
    CONSTRAINT chk_liabilities_credit_card_fields CHECK (
        (
            kind = 'credit_card'
            AND credit_limit IS NOT NULL
            AND statement_close_day IS NOT NULL
            AND statement_close_day BETWEEN 1 AND 28
            AND payment_due_day IS NOT NULL
            AND payment_due_day BETWEEN 1 AND 28
        )
        OR
        (
            kind <> 'credit_card'
            AND credit_limit IS NULL
            AND statement_close_day IS NULL
            AND payment_due_day IS NULL
        )
    ),
    CONSTRAINT chk_liabilities_interest_rate CHECK (
        annual_interest_rate IS NULL OR annual_interest_rate <= 100.000
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS liability_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    liability_id BIGINT UNSIGNED NOT NULL,
    operation_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('increase', 'decrease') NOT NULL,
    amount DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0.00,
    interest_amount DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_liability_movements_operation (operation_id, liability_id),
    KEY idx_liability_movements_liability (liability_id, id),
    CONSTRAINT fk_liability_movements_liability FOREIGN KEY (liability_id) REFERENCES liabilities (id),
    CONSTRAINT fk_liability_movements_operation FOREIGN KEY (operation_id) REFERENCES operations (id),
    CONSTRAINT chk_liability_movements_amount CHECK (amount > 0.00 OR interest_amount > 0.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS account_action_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('verify_email', 'reset_password') NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_account_action_token_hash (token_hash),
    KEY idx_account_action_user_purpose (user_id, purpose, created_at),
    CONSTRAINT fk_account_action_tokens_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO categories (code, name, kind, sort_order) VALUES
    ('loan_interest', 'Intereses de deudas', 'expense', 95)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    sort_order = VALUES(sort_order);
