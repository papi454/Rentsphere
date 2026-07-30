-- =========================================================
-- Migration: Multi-Company Support
-- Adds a unique, URL-safe slug to each company (e.g. "primenest-agencies")
-- so each company gets its own tenant sign-up link and stays fully
-- separate from other companies using the same RentSphere install.
-- =========================================================
USE rentsphere;

ALTER TABLE companies
    ADD COLUMN slug VARCHAR(120) NULL UNIQUE AFTER name;

-- Backfill a slug for any company that already exists (e.g. your current one)
UPDATE companies
SET slug = LOWER(TRIM(BOTH '-' FROM REGEXP_REPLACE(name, '[^a-zA-Z0-9]+', '-')))
WHERE slug IS NULL;
