-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: db/push_history.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE push_history (
  ph_page INT NOT NULL, ph_user INT NOT NULL,
  ph_target TEXT NOT NULL, ph_timestamp TIMESTAMPTZ NOT NULL
);