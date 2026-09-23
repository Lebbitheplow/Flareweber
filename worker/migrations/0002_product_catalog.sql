-- Product catalog columns per contract E (is_active, price, sku, gallery).
ALTER TABLE products RENAME COLUMN published TO is_active;
ALTER TABLE products ADD COLUMN price_cents INTEGER NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN currency TEXT NOT NULL DEFAULT 'USD';
ALTER TABLE products ADD COLUMN sku TEXT;
ALTER TABLE products ADD COLUMN images_json TEXT;
CREATE INDEX IF NOT EXISTS idx_products_active ON products(is_active);
