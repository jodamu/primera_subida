CREATE TABLE IF NOT EXISTS account_types (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(30) NOT NULL,
    name VARCHAR(60) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_account_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_type_id SMALLINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    scope ENUM('personal', 'household') NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    household_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    initial_balance DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0.00,
    initial_balance_date DATE NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'COP',
    overdraft_enabled TINYINT(1) NOT NULL DEFAULT 0,
    overdraft_limit DECIMAL(15,2) UNSIGNED NOT NULL DEFAULT 0.00,
    status ENUM('active', 'archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_accounts_owner_status (owner_user_id, status),
    KEY idx_accounts_household_status (household_id, status),
    KEY idx_accounts_type (account_type_id),
    CONSTRAINT fk_accounts_type FOREIGN KEY (account_type_id) REFERENCES account_types (id),
    CONSTRAINT fk_accounts_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
    CONSTRAINT fk_accounts_household FOREIGN KEY (household_id) REFERENCES households (id),
    CONSTRAINT fk_accounts_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT chk_accounts_scope CHECK (
        (scope = 'personal' AND owner_user_id IS NOT NULL AND household_id IS NULL)
        OR
        (scope = 'household' AND owner_user_id IS NULL AND household_id IS NOT NULL)
    ),
    CONSTRAINT chk_accounts_overdraft CHECK (
        overdraft_enabled = 1 OR overdraft_limit = 0.00
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS categories (
    id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(80) NOT NULL,
    kind ENUM('income', 'expense') NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_kind_code (kind, code),
    KEY idx_categories_kind_active (kind, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS operations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_type ENUM('income', 'expense', 'transfer', 'adjustment', 'reversal') NOT NULL,
    status ENUM('draft', 'confirmed', 'cancelled') NOT NULL DEFAULT 'draft',
    scope ENUM('personal', 'household') NOT NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    household_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    category_id SMALLINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    effective_date DATE NOT NULL,
    idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reversal_of_operation_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_operations_idempotency (created_by, idempotency_key),
    UNIQUE KEY uq_operations_reversal (reversal_of_operation_id),
    KEY idx_operations_owner_date (owner_user_id, effective_date, id),
    KEY idx_operations_household_date (household_id, effective_date, id),
    KEY idx_operations_category_date (category_id, effective_date),
    CONSTRAINT fk_operations_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
    CONSTRAINT fk_operations_household FOREIGN KEY (household_id) REFERENCES households (id),
    CONSTRAINT fk_operations_creator FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_operations_category FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT fk_operations_reversal FOREIGN KEY (reversal_of_operation_id) REFERENCES operations (id),
    CONSTRAINT chk_operations_scope CHECK (
        (scope = 'personal' AND owner_user_id IS NOT NULL AND household_id IS NULL)
        OR
        (scope = 'household' AND owner_user_id IS NULL AND household_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    operation_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('inflow', 'outflow') NOT NULL,
    amount DECIMAL(15,2) UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_movements_operation_account (operation_id, account_id),
    UNIQUE KEY uq_movements_id_account (id, account_id),
    KEY idx_movements_account_id (account_id, id),
    CONSTRAINT fk_movements_operation FOREIGN KEY (operation_id) REFERENCES operations (id),
    CONSTRAINT fk_movements_account FOREIGN KEY (account_id) REFERENCES accounts (id),
    CONSTRAINT chk_movements_amount CHECK (amount > 0.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS transfers (
    operation_id BIGINT UNSIGNED NOT NULL,
    source_movement_id BIGINT UNSIGNED NOT NULL,
    destination_movement_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (operation_id),
    UNIQUE KEY uq_transfers_source (source_movement_id),
    UNIQUE KEY uq_transfers_destination (destination_movement_id),
    CONSTRAINT fk_transfers_operation FOREIGN KEY (operation_id) REFERENCES operations (id),
    CONSTRAINT fk_transfers_source FOREIGN KEY (source_movement_id) REFERENCES movements (id),
    CONSTRAINT fk_transfers_destination FOREIGN KEY (destination_movement_id) REFERENCES movements (id),
    CONSTRAINT chk_transfers_distinct_movements CHECK (source_movement_id <> destination_movement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO account_types (code, name, sort_order) VALUES
    ('savings', 'Cuenta de ahorros', 10),
    ('checking', 'Cuenta corriente', 20),
    ('cash', 'Efectivo', 30),
    ('nequi', 'Nequi', 40),
    ('daviplata', 'Daviplata', 50),
    ('digital_wallet', 'Billetera digital', 60),
    ('other', 'Otra cuenta', 90)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    sort_order = VALUES(sort_order);

ALTER TABLE audit_log
    ADD COLUMN target_type VARCHAR(40) NULL AFTER event_type,
    ADD COLUMN target_id BIGINT UNSIGNED NULL AFTER target_type,
    ADD KEY idx_audit_target (target_type, target_id, created_at);

INSERT INTO categories (code, name, kind, sort_order) VALUES
    ('salary', 'Salario', 'income', 10),
    ('bonus', 'Bonificaciones', 'income', 20),
    ('commission', 'Comisiones', 'income', 30),
    ('side_income', 'Ingresos adicionales', 'income', 40),
    ('other_income', 'Otros ingresos', 'income', 90),
    ('food', 'Alimentación', 'expense', 10),
    ('housing', 'Vivienda', 'expense', 20),
    ('utilities', 'Servicios públicos', 'expense', 30),
    ('transport', 'Transporte', 'expense', 40),
    ('health', 'Salud', 'expense', 50),
    ('education', 'Educación', 'expense', 60),
    ('entertainment', 'Entretenimiento', 'expense', 70),
    ('shopping', 'Compras', 'expense', 80),
    ('debt', 'Deudas', 'expense', 90),
    ('pets', 'Mascotas', 'expense', 100),
    ('other_expense', 'Otros gastos', 'expense', 900)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    sort_order = VALUES(sort_order);
