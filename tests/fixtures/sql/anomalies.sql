INSERT INTO {{schema}}.virtual_domains (id, name) VALUES (11, 'Example.com');

INSERT INTO {{schema}}.virtual_users (id, domain_id, email, password, quota) VALUES
  (10, 10, 'Mixed@example.org', '{BLF-CRYPT}$2y$05$7rFPPTUz0YcSkwcpz7tRoOMf54qNHPr2uGY18qW5rAnuIRpgE3e6O', 0),
  (11, 10, 'wrong@example.net', '{BLF-CRYPT}$2y$05$7rFPPTUz0YcSkwcpz7tRoOMf54qNHPr2uGY18qW5rAnuIRpgE3e6O', 0),
  (12, 10, 'weak@example.org', '{PLAIN-MD5}14cbfb845af1f030e372b1cb9275e6dd', 0),
  (13, 10, 'nohash@example.org', 'summersun', 0),
  (14, 10, 'jörg@example.org', '{BLF-CRYPT}$2y$05$7rFPPTUz0YcSkwcpz7tRoOMf54qNHPr2uGY18qW5rAnuIRpgE3e6O', 0);

INSERT INTO {{schema}}.virtual_aliases (id, domain_id, source, destination) VALUES
  (10, 10, 'mary@example.org', 'john@example.org'),
  (11, 10, 'jack@example.org', 'john@example.org'),
  (12, 10, '@example.org', 'john@example.org'),
  (13, 10, 'john@example.org', 'john@example.org'),
  (14, 10, 'x@example.net', 'john@example.org');
