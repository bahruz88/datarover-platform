-- DataRover schema migration to v1.4
-- Idempotent: safe to run multiple times. Adds new tables/columns introduced
-- after the original db-init dump.

-- 1. created_by column on glossary_terms (used by visibility/edit rules)
ALTER TABLE glossary_terms
    ADD COLUMN IF NOT EXISTS created_by VARCHAR(255) NULL AFTER owner;

-- 2. Term version snapshots (reopen / publish workflow)
CREATE TABLE IF NOT EXISTS glossary_term_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term_id INT NOT NULL,
    version_number INT NOT NULL,
    name VARCHAR(255),
    abbreviation VARCHAR(50),
    definition TEXT,
    domain VARCHAR(100),
    data_type VARCHAR(100),
    example TEXT,
    formula TEXT,
    business_logic TEXT,
    technical_description TEXT,
    owner VARCHAR(255),
    created_by VARCHAR(255),
    stewards LONGTEXT,
    physical_attributes LONGTEXT,
    quality_rules LONGTEXT,
    synonyms LONGTEXT,
    related_terms LONGTEXT,
    source_system VARCHAR(255),
    notes TEXT,
    security_classification VARCHAR(50),
    status VARCHAR(50),
    snapshot_action VARCHAR(50),
    snapshot_by VARCHAR(255),
    snapshot_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_term_id (term_id),
    KEY idx_version_number (version_number)
);

-- 3. Notification read tracking ("hamısını oxudum" button)
CREATE TABLE IF NOT EXISTS notification_reads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    term_id INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_term_status (user_id, term_id, status),
    KEY idx_user_id (user_id)
);

-- 4. utf8mb4 on workflow icons so emoji (📦, 🔍, etc.) save correctly
ALTER TABLE governance_workflow_steps
    MODIFY COLUMN icon VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 5. Workflow rules per the v1.4 process (Steward submits, Owner approves,
--    Steward publishes, Owner deprecates).
UPDATE governance_workflow_steps SET created_by = '["data_steward"]', approved_by = '["data_steward"]', next_step = 'review',     reject_step = NULL          WHERE step_id = 'draft';
UPDATE governance_workflow_steps SET created_by = NULL,               approved_by = '["domain_owner"]', next_step = 'approved',  reject_step = 'rejected'    WHERE step_id = 'review';
UPDATE governance_workflow_steps SET created_by = NULL,               approved_by = '["data_steward"]', next_step = 'published', reject_step = 'review'      WHERE step_id = 'approved';
UPDATE governance_workflow_steps SET created_by = NULL,               approved_by = '["domain_owner"]', next_step = 'deprecated', reject_step = 'deprecated' WHERE step_id = 'published';
UPDATE governance_workflow_steps SET created_by = NULL,               approved_by = '["data_steward"]', next_step = 'draft',     reject_step = NULL          WHERE step_id = 'rejected';
UPDATE governance_workflow_steps SET created_by = NULL,               approved_by = NULL,               next_step = NULL,        reject_step = NULL          WHERE step_id = 'deprecated';

-- 6. Workflow step icons
UPDATE governance_workflow_steps SET icon = '✏️' WHERE step_id = 'draft';
UPDATE governance_workflow_steps SET icon = '🔍' WHERE step_id = 'review';
UPDATE governance_workflow_steps SET icon = '✅' WHERE step_id = 'approved';
UPDATE governance_workflow_steps SET icon = '📢' WHERE step_id = 'published';
UPDATE governance_workflow_steps SET icon = '❌' WHERE step_id = 'rejected';
UPDATE governance_workflow_steps SET icon = '📦' WHERE step_id = 'deprecated';
