-- Seeds never DELETE; dropped product-to-category mappings are flagged active = 0.
ALTER TABLE product_categories ADD COLUMN active INTEGER NOT NULL DEFAULT 1;
