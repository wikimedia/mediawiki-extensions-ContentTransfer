CREATE TABLE IF NOT EXISTS /*$wgDBprefix*/push_history (
  ph_page INT(6) NOT NULL,
  ph_user INT(6) NOT NULL,
  ph_target VARCHAR(255) NOT NULL,
  ph_timestamp VARCHAR(15) NOT NULL
) /*$wgDBTableOptions*/;
