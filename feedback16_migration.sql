-- ============================================================
-- Sixteenth Feedback Round Migration — Inventory Pro
-- Run once against the `inventorymanagement` database (production).
-- Wires up the previously-unused `user_sessions` table to actually track
-- login/logout/activity, so admins can see who's currently active and a
-- full login history log with session duration.
-- ============================================================

USE `inventorymanagement`;

ALTER TABLE `user_sessions`
  ADD COLUMN `logout_at` DATETIME DEFAULT NULL
    COMMENT 'Set when the user explicitly logs out. NULL for sessions still open or abandoned (browser closed without logging out).'
    AFTER `expires_at`,
  ADD COLUMN `last_activity_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    COMMENT 'Refreshed periodically while the session is in use — used to tell "still active" apart from an abandoned session.'
    AFTER `logout_at`;
