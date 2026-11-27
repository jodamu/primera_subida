ALTER TABLE categories
    ADD COLUMN scope ENUM('personal', 'household') NULL AFTER kind,
    ADD COLUMN owner_user_id BIGINT UNSIGNED NULL AFTER scope,
    ADD COLUMN household_id BIGINT UNSIGNED NULL AFTER owner_user_id,
    ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER household_id,
    ADD KEY idx_categories_space (scope, owner_user_id, household_id, kind, is_active),
    ADD CONSTRAINT fk_categories_owner FOREIGN KEY (owner_user_id) REFERENCES users (id),
    ADD CONSTRAINT fk_categories_household FOREIGN KEY (household_id) REFERENCES households (id),
    ADD CONSTRAINT fk_categories_creator FOREIGN KEY (created_by) REFERENCES users (id),
    ADD CONSTRAINT chk_categories_space CHECK (
        (scope IS NULL AND owner_user_id IS NULL AND household_id IS NULL)
        OR (scope = 'personal' AND owner_user_id IS NOT NULL AND household_id IS NULL)
        OR (scope = 'household' AND household_id IS NOT NULL AND owner_user_id IS NULL)
    )
