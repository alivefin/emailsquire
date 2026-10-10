-- ISPmail Admin 0.9.12, README.txt: the blacklist_email table. IMA runs htmlentities() on input.
CREATE TABLE `{{schema}}`.`blacklist_email` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `address` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL,
  `reason` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_nopad_ci;

ALTER TABLE `{{schema}}`.`blacklist_email` ADD PRIMARY KEY (`id`), ADD UNIQUE KEY `address` (`address`) USING BTREE;
ALTER TABLE `{{schema}}`.`blacklist_email` MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

INSERT INTO `{{schema}}`.`blacklist_email` (`id`, `address`, `reason`) VALUES
  (1, 'old@example.org', 'spam &amp; abuse'),
  (2, 'Anna@Example.ORG', ''),
  (3, 'john@example.org', 'already there'),
  (4, 'bad..x@example.org', 'broken'),
  (5, 'spaced@example.org ', 'trailing space');
