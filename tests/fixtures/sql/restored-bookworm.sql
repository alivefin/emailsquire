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
  UNIQUE KEY `email` (`email`),
  FOREIGN KEY (domain_id) REFERENCES {{schema}}.virtual_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS {{schema}}.`virtual_aliases` (
  `id` int(11) NOT NULL auto_increment,
  `domain_id` int(11) NOT NULL,
  `source` varchar(100) NOT NULL,
  `destination` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (domain_id) REFERENCES {{schema}}.virtual_domains(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

INSERT INTO {{schema}}.virtual_domains (id, name) VALUES (10, 'example.org');

INSERT INTO {{schema}}.virtual_users (id, domain_id, email, password, quota) VALUES
  (1, 10, 'john@example.org', '{BLF-CRYPT}$2y$05$7rFPPTUz0YcSkwcpz7tRoOMf54qNHPr2uGY18qW5rAnuIRpgE3e6O', 0),
  (3, 10, 'legacy@example.org', '{SHA512-CRYPT}$6$3c9705362f29bbe5$OycqAm4Q4OGcMnR6QkUhDUqfRSbzUm5VRoxNMqDkJys3BVsPtPqcVDVrxD7ky8re3edkRfUHCTScf9Gv7ayRy/', 0);
