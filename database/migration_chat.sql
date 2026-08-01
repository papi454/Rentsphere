-- =========================================================
-- Migration: Property Chat Groups
-- Each property automatically has one chat group. Membership
-- isn't stored explicitly — it's computed from existing data:
--   - Administrators: any property in their own company
--   - Caretakers: only their assigned property/properties
--   - Tenants: only the property tied to their active lease
-- =========================================================
USE rentsphere;

CREATE TABLE chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    property_id INT NOT NULL,
    sender_user_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_chat_property (property_id, created_at)
) ENGINE=InnoDB;
