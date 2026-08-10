-- Scope push subscriptions to the app that created them.
--
-- tp-checkin is getting Web Push and shares this table (same tp_crm database,
-- same user ids). Endpoints are globally unique so they never collide, but the
-- *payload* is app-specific: a notification carries a path like
-- /leave_history.php that the service worker resolves against its own origin.
-- Push a TP-HR leave decision to a subscription made on checkin.tp-asset.com
-- and the employee lands on a checkin URL that does not exist.
--
-- Additive and reversible: one new column, one new index, no data rewritten.
-- Existing rows all came from TP-HR, which is what the DEFAULT backfills.
--
-- Rollback:
--   ALTER TABLE hr_push_subscriptions DROP INDEX idx_hr_push_user_app;
--   ALTER TABLE hr_push_subscriptions DROP COLUMN app;

-- Appended rather than placed AFTER user_id so MySQL 8 can use ALGORITHM=INSTANT
-- and skip the table rebuild.
ALTER TABLE hr_push_subscriptions
    ADD COLUMN app VARCHAR(32) NOT NULL DEFAULT 'tp-hr'
        COMMENT 'origin that owns the subscription: tp-hr | tp-checkin';

-- Every lookup is (user_id, app). The older idx_hr_push_user is left in place:
-- this index supersedes it, but dropping an index on a shared production table
-- buys nothing on a table this small.
ALTER TABLE hr_push_subscriptions
    ADD INDEX idx_hr_push_user_app (user_id, app);
