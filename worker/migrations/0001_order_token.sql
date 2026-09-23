-- Order token, cart link and payment bookkeeping on orders.
ALTER TABLE orders ADD COLUMN order_token TEXT;
ALTER TABLE orders ADD COLUMN cart_id TEXT;
ALTER TABLE orders ADD COLUMN stripe_payment_intent_id TEXT;
ALTER TABLE orders ADD COLUMN paid_cents INTEGER;
ALTER TABLE orders ADD COLUMN updated_at TEXT;
CREATE INDEX IF NOT EXISTS idx_orders_token ON orders(order_token);
CREATE INDEX IF NOT EXISTS idx_orders_payment_intent ON orders(stripe_payment_intent_id);
