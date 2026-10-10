CREATE TABLE IF NOT EXISTS {{schema}}.`virtual_domains` (
  `id` int(11) NOT NULL auto_increment,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS {{schema}}.`virtual_users` (
  `id` int(11) NOT NULL auto_increment,
  `domain_id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(150) NOT NULL,
  `quota` bigint(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS {{schema}}.`virtual_aliases` (
  `id` int(11) NOT NULL auto_increment,
  `domain_id` int(11) NOT NULL,
  `source` varchar(100) NOT NULL,
  `destination` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO {{schema}}.virtual_domains (id, name) VALUES (10, 'example.org');

INSERT INTO {{schema}}.virtual_users (id, domain_id, email, password, quota) VALUES
  (1, 10, 'john@example.org', '{BLF-CRYPT}$2y$05$7rFPPTUz0YcSkwcpz7tRoOMf54qNHPr2uGY18qW5rAnuIRpgE3e6O', 0),
  (2, 10, 'mary@example.org', '{CRYPT}$2y$05$7rFPPTUz0YcSkwcpz7tRoOMf54qNHPr2uGY18qW5rAnuIRpgE3e6O', 0);

INSERT INTO {{schema}}.virtual_aliases (id, domain_id, source, destination) VALUES
  (1, 10, 'jack@example.org', 'john@example.org');
