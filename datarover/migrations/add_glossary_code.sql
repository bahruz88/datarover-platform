-- Add `code` identifier column to glossary_terms (e.g., gloss-1, gloss-2).
-- Run once via phpMyAdmin or mysql CLI against the datarover database.

ALTER TABLE glossary_terms
    ADD COLUMN code VARCHAR(20) NULL UNIQUE AFTER id;

UPDATE glossary_terms
    SET code = CONCAT('Gloss-', id)
    WHERE code IS NULL;
