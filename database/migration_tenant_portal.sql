-- =========================================================
-- Optional: Tenant Portal Login
-- Run this AFTER the main rentsphere.sql if you want tenants
-- to be able to log into their own portal (tenant/dashboard.php)
-- using the same secure login/2FA flow as admins/caretakers.
-- =========================================================
USE rentsphere;

ALTER TABLE users
    MODIFY role ENUM('administrator','caretaker','tenant') NOT NULL DEFAULT 'administrator';

-- Links a tenant's login account back to their tenant profile
ALTER TABLE tenants
    ADD CONSTRAINT fk_tenants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

-- To create a tenant login manually for now (until you build a
-- "Send portal invite" button in admin/tenants.php), an admin can run:
--
-- INSERT INTO users (company_id, role, first_name, last_name, email, password_hash, is_email_verified)
-- VALUES (1, 'tenant', 'Jane', 'Doe', 'jane@example.com', '<password_hash() output>', 1);
--
-- UPDATE tenants SET user_id = <new user id> WHERE id = <tenant id>;
