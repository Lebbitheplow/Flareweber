<?php

namespace FlareWeber\Tests\Unit;

use FlareWeber\Compiler\SeedGenerator;
use PHPUnit\Framework\TestCase;

class SeedGeneratorTest extends TestCase
{
    private function product(array $overrides = []): array
    {
        return $overrides + [
            'id' => 12,
            'title' => "Bob's Mug",
            'slug' => 'bobs-mug',
            'description' => "A mug; it's -- great",
            'price_cents' => 1250,
            'currency' => 'usd',
            'quantity' => 7,
            'sku' => 'MUG-1',
            'image' => '/media/default/mug.jpg',
            'images' => ['/media/default/mug.jpg', '/media/default/mug-2.jpg'],
            'categories' => [['id' => 3, 'name' => 'Kitchen', 'slug' => 'kitchen']],
            'is_active' => true,
        ];
    }

    public function testEmitsUpsertsAndNeverDeletes(): void
    {
        $sql = (new SeedGenerator())->generate([$this->product()], ['site_name' => 'Shop']);

        $this->assertStringNotContainsStringIgnoringCase('DELETE FROM', $sql);
        $this->assertStringContainsString('ON CONFLICT(id) DO UPDATE', $sql);
        $this->assertStringContainsString(
            "INSERT INTO products (id, slug, title, description, image, is_active, price_cents, currency, sku, images_json) "
            . "VALUES (12, 'bobs-mug', 'Bob''s Mug', 'A mug; it''s -- great', '/media/default/mug.jpg', 1, 1250, 'USD', 'MUG-1', "
            . "'[\"/media/default/mug.jpg\",\"/media/default/mug-2.jpg\"]')",
            $sql
        );
        $this->assertStringContainsString("INSERT INTO product_variants (id, product_id, sku, title, price_cents, currency) VALUES (120, 12, 'MUG-1', 'Default', 1250, 'USD')", $sql);
        $this->assertStringContainsString('INSERT INTO inventory (variant_id, quantity) VALUES (120, 7) ON CONFLICT(variant_id) DO UPDATE SET quantity = excluded.quantity;', $sql);
        $this->assertStringContainsString("INSERT INTO categories (id, slug, name, parent_id) VALUES (3, 'kitchen', 'Kitchen', NULL)", $sql);
        $this->assertStringContainsString('INSERT INTO product_categories (product_id, category_id, active) VALUES (12, 3, 1)', $sql);
        $this->assertStringContainsString('UPDATE product_categories SET active = 0 WHERE product_id = 12 AND category_id NOT IN (3);', $sql);
        $this->assertStringContainsString("INSERT INTO settings (key, value) VALUES ('site_name', 'Shop') ON CONFLICT(key) DO UPDATE", $sql);
        $this->assertStringContainsString('UPDATE products SET is_active = 0 WHERE id NOT IN (12);', $sql);
    }

    public function testInventoryIsInsertOnlyWhenSyncDisabled(): void
    {
        $sql = (new SeedGenerator())->generate([$this->product()], [], false);

        $this->assertStringContainsString('INSERT INTO inventory (variant_id, quantity) VALUES (120, 7) ON CONFLICT(variant_id) DO NOTHING;', $sql);
        $this->assertStringNotContainsString('SET quantity = excluded.quantity', $sql);
    }

    public function testUnlimitedAndInactiveProducts(): void
    {
        $sql = (new SeedGenerator())->generate([
            $this->product(['id' => 1, 'quantity' => -1, 'is_active' => false, 'image' => null, 'sku' => null, 'images' => []]),
            $this->product(['id' => 2, 'slug' => 'two', 'quantity' => 'nolimit']),
        ]);

        $this->assertStringContainsString('VALUES (10, -1)', $sql);
        $this->assertStringContainsString('VALUES (20, -1)', $sql);
        $this->assertStringContainsString("'bobs-mug', 'Bob''s Mug', 'A mug; it''s -- great', NULL, 0, 1250, 'USD', NULL, '[]')", $sql);
        $this->assertStringContainsString("VALUES (10, 1, NULL, 'Default'", $sql);
        $this->assertStringContainsString('UPDATE products SET is_active = 0 WHERE id NOT IN (1, 2);', $sql);
    }

    public function testExplicitVariantsReplaceTheDefaultVariant(): void
    {
        $sql = (new SeedGenerator())->generate([
            $this->product(['variants' => [
                ['id' => 501, 'title' => 'Small', 'sku' => 'S', 'price_cents' => 1000, 'quantity' => 2],
                ['id' => 502, 'title' => 'Large', 'sku' => 'L', 'price_cents' => 1500, 'quantity' => -1],
            ]]),
        ]);

        $this->assertStringNotContainsString('VALUES (120, 12', $sql);
        $this->assertStringContainsString("VALUES (501, 12, 'S', 'Small', 1000, 'USD')", $sql);
        $this->assertStringContainsString("VALUES (502, 12, 'L', 'Large', 1500, 'USD')", $sql);
        $this->assertStringContainsString('VALUES (502, -1)', $sql);
    }

    public function testNoProductsDeactivatesEverything(): void
    {
        $sql = (new SeedGenerator())->generate([]);

        $this->assertStringContainsString("UPDATE products SET is_active = 0;\n", $sql);
        $this->assertStringNotContainsStringIgnoringCase('DELETE FROM', $sql);
    }

    public function testCategoriesWithoutMappingsAreAllDeactivated(): void
    {
        $sql = (new SeedGenerator())->generate([$this->product(['categories' => []])]);

        $this->assertStringContainsString('UPDATE product_categories SET active = 0 WHERE product_id = 12;', $sql);
        $this->assertStringNotContainsString('INSERT INTO categories', $sql);
    }
}
