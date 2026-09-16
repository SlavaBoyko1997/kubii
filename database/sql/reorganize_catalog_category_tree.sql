-- One-shot catalog category tree reorganization for MySQL 8+
-- Run on production AFTER backup. Safe to re-run only before categories are deactivated.
-- Prefer: php artisan migrate --path=database/migrations/2026_06_26_150000_reorganize_catalog_category_tree.php

START TRANSACTION;

CREATE TABLE IF NOT EXISTS category_slug_redirects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    old_path VARCHAR(255) NOT NULL UNIQUE,
    category_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT category_slug_redirects_category_id_foreign FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

-- Special splits
UPDATE products SET category_id = 433
WHERE category_id = 379 AND (
    LOWER(name) LIKE '%гамак%' OR LOWER(name) LIKE '%hammock%'
    OR LOWER(COALESCE(name_ru, '')) LIKE '%гамак%' OR LOWER(COALESCE(name_ru, '')) LIKE '%hammock%'
);
UPDATE products SET category_id = 434 WHERE category_id = 379;

UPDATE products SET category_id = 367
WHERE category_id = 222 AND (
    LOWER(name) LIKE '%літн%' OR LOWER(name) LIKE '%litn%' OR LOWER(name) LIKE '%summer%'
    OR LOWER(COALESCE(name_ru, '')) LIKE '%летн%' OR LOWER(COALESCE(name_ru, '')) LIKE '%summer%'
);
UPDATE products SET category_id = 368
WHERE category_id = 222 AND (
    LOWER(name) LIKE '%зим%' OR LOWER(name) LIKE '%zym%' OR LOWER(name) LIKE '%winter%'
    OR LOWER(COALESCE(name_ru, '')) LIKE '%зим%' OR LOWER(COALESCE(name_ru, '')) LIKE '%winter%'
);
UPDATE products SET category_id = 369
WHERE category_id = 222 AND (
    LOWER(name) LIKE '%всесез%' OR LOWER(name) LIKE '%all season%'
    OR LOWER(COALESCE(name_ru, '')) LIKE '%всесез%'
);
UPDATE products SET category_id = 12 WHERE category_id = 222;

-- Tactical backpacks
UPDATE products SET category_id = 479 WHERE category_id = 305;

-- Promo categories
UPDATE products SET category_id = 448 WHERE category_id = 151;
UPDATE products SET category_id = 490 WHERE category_id = 108;
UPDATE products SET category_id = 467 WHERE category_id = 84;

DROP TEMPORARY TABLE IF EXISTS category_merge_map;
CREATE TEMPORARY TABLE category_merge_map (
    source_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    target_id BIGINT UNSIGNED NOT NULL
);

INSERT INTO category_merge_map (target_id, source_id) VALUES
(392,362),(363,363),(363,244),(364,364),(364,245),(365,365),(365,247),(393,366),(393,393),(393,246),
(367,367),(367,251),(368,368),(368,250),(369,369),(369,249),(370,370),(370,248),
(394,371),(394,394),(394,254),(395,136),(395,372),(395,395),(395,256),(396,373),(396,396),(396,259),
(397,375),(397,397),(397,260),(398,376),(398,398),(398,221),(398,257),(399,377),(399,399),(399,255),
(428,203),(428,380),(428,428),(429,264),(429,381),(429,429),(430,265),(430,382),(430,430),
(431,263),(431,383),(431,431),(432,262),(432,384),(432,432),(433,220),(433,433),(434,252),(434,434),
(421,177),(421,218),(421,297),(421,298),(421,421),
(479,143),(479,288),(479,479),
(436,299),(436,386),(436,436),(437,241),(437,313),(437,385),(437,437),
(438,238),(438,294),(438,438),(439,285),(439,286),(439,290),(439,439),
(440,126),(440,340),(440,341),(440,342),(440,440),
(441,205),(441,206),(441,234),(441,309),(441,332),(441,441),
(413,180),(413,183),(413,217),(413,227),(413,339),(413,413),
(414,181),(414,219),(414,278),(414,310),(414,414),
(416,186),(416,225),(416,230),(416,272),(416,359),(416,416),
(417,190),(417,212),(417,417),(418,187),(418,189),(418,211),(418,237),(418,418),
(419,188),(419,210),(419,228),(419,229),(419,271),(419,419),
(420,314),(420,315),(420,343),(420,420),
(400,13),(400,167),(400,169),(400,170),(400,196),(400,197),(400,198),(400,199),(400,200),(400,214),(400,311),(400,337),
(401,172),(401,267),(401,401),(402,171),(402,266),(402,402),(403,201),(403,403),
(404,168),(404,331),(404,404),(405,173),(405,174),(405,405),
(443,307),(443,443),(444,306),(444,444),(445,327),(445,445),
(407,155),(407,156),(407,157),(407,158),(407,159),(407,160),(407,179),(407,207),(407,208),(407,209),
(407,226),(407,233),(407,235),(407,236),(407,351),(407,355),(407,356),(407,357),(407,358),
(408,83),(408,175),(408,338),(408,352),(408,353),(408,354),
(409,164),(409,165),(409,166),(409,178),(409,192),(409,193),(409,194),(409,195),(409,202),(409,239),
(410,152),(410,153),(410,176),(410,184),(410,213),(410,301),(410,308),(410,334),(410,335),
(411,154),(411,317),(411,318),(411,319),(411,320),(411,321),(411,322),(411,323),
(447,61),(448,77),(448,110),(448,111),(449,75),(449,117),(450,74),(450,76),(450,114),(450,115),
(451,65),(451,66),(451,67),(451,72),(451,119),(451,120),(451,129),(451,269),
(452,70),(452,71),(452,78),(452,122),(452,123),(452,124),(453,73),(453,121),
(454,60),(454,118),(454,270),(455,62),(455,63),(455,64),(455,128),
(456,50),(457,53),(458,52),(459,54),(459,55),(460,51),
(461,56),(462,57),(463,59),(464,58),
(465,47),(465,49),(466,79),(467,92),(467,112),(468,116),(468,127),
(6,93),(6,240),(6,282),(471,85),
(472,86),(472,125),(473,48),(473,89),(474,90),(474,161),(475,87),(475,162),(475,287),(475,304),(475,361),(475,424),
(476,68),(476,99),(476,100),(476,101),(476,102),(476,103),(476,104),(476,105),(476,106),(476,163),(476,425),
(477,14),(480,137),(480,142),(481,141),(481,147),(482,139),(483,145),(483,146),(484,130),(484,131),(484,132),(484,133),
(485,140),(485,148),(486,135),(487,149),(487,150),(488,138),(489,191),(489,316),(489,324),(489,325),(489,326),
(490,144),(490,91),(378,81),(446,82),(446,107)
ON DUPLICATE KEY UPDATE target_id = VALUES(target_id);

DELETE FROM category_merge_map WHERE source_id = target_id;

UPDATE products p
INNER JOIN category_merge_map m ON p.category_id = m.source_id
SET p.category_id = m.target_id, p.updated_at = NOW();

UPDATE categories c
INNER JOIN category_merge_map m ON c.target_category_id = m.source_id
SET c.target_category_id = m.target_id, c.updated_at = NOW();

UPDATE categories c
INNER JOIN category_merge_map m ON c.id = m.source_id
SET c.is_active = 0, c.target_category_id = m.target_id, c.updated_at = NOW();

UPDATE categories SET is_active = 0, updated_at = NOW() WHERE id IN (84,151,108,7,134,86,80);

INSERT INTO categories (name, name_ru, slug, slug_ru, parent_id, sort_order, is_active, created_at, updated_at)
SELECT 'Ліхтарі та електроживлення', 'Фонари и электропитание', 'lihtari-ta-elektrozyvlennia', 'lihtari-ta-elektrozyvlennia', NULL, 15, 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE slug = 'lihtari-ta-elektrozyvlennia');

UPDATE categories SET parent_id = (SELECT id FROM (SELECT id FROM categories WHERE slug = 'lihtari-ta-elektrozyvlennia') t), updated_at = NOW()
WHERE id IN (400, 442);

UPDATE categories SET parent_id = NULL, updated_at = NOW() WHERE id IN (2, 5, 10, 406, 412, 478);
UPDATE categories SET parent_id = 5, updated_at = NOW() WHERE id = 422;
UPDATE categories SET parent_id = 412, updated_at = NOW() WHERE id = 415;

COMMIT;

-- After SQL: php artisan optimize:clear && warm catalog cache in admin
