-- =========================================================
-- Migration: Tenant Self-Registration + Approval Flow
-- Run this AFTER migration_tenant_portal.sql
-- =========================================================
USE rentsphere;

ALTER TABLE tenants
    MODIFY status ENUM('pending','active','former') NOT NULL DEFAULT 'pending';
