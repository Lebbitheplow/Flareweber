<?php

namespace FlareWeber\Admin;

use FlareWeber\Cloudflare\CloudflareClient;
use FlareWeber\Models\Site;

/**
 * Orders live in the site's production D1 database (worker/schema.sql:
 * `orders` + `order_items`). This reads and updates them through the
 * Cloudflare REST API: POST /accounts/{account}/d1/database/{id}/query.
 */
class D1OrdersRepository
{
    public const OPEN_STATUSES = ['paid', 'processing'];

    public const UPDATABLE_STATUSES = ['fulfilled', 'cancelled'];

    private const ORDER_COLUMNS = 'id, status, payment_status, email, total_cents, currency, created_at';

    public function __construct(private readonly Site $site)
    {
    }

    public function available(): bool
    {
        return $this->databaseId() !== null && $this->site->cloudflareConnection !== null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(int $limit = 100): array
    {
        $orders = $this->query(
            'SELECT ' . self::ORDER_COLUMNS . ' FROM orders ORDER BY id DESC LIMIT ?',
            [$limit]
        );

        return $this->attachItems($orders);
    }

    public function openCount(): int
    {
        $placeholders = implode(',', array_fill(0, count(self::OPEN_STATUSES), '?'));
        $rows = $this->query(
            "SELECT COUNT(*) AS c FROM orders WHERE status IN ({$placeholders})",
            self::OPEN_STATUSES
        );

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null the updated order, or null when unknown
     */
    public function updateStatus(int $id, string $status): ?array
    {
        $this->query('UPDATE orders SET status = ? WHERE id = ?', [$status, $id]);

        $rows = $this->query('SELECT ' . self::ORDER_COLUMNS . ' FROM orders WHERE id = ?', [$id]);
        if ($rows === []) {
            return null;
        }

        return $this->attachItems($rows)[0];
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function attachItems(array $orders): array
    {
        if ($orders === []) {
            return [];
        }

        $ids = array_map(fn (array $order) => (int) $order['id'], $orders);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $rows = $this->query(
            'SELECT oi.order_id, oi.variant_id, oi.quantity, oi.unit_price_cents, '
            . 'pv.title AS variant_title, pv.product_id, p.title AS product_title '
            . 'FROM order_items oi '
            . 'LEFT JOIN product_variants pv ON pv.id = oi.variant_id '
            . 'LEFT JOIN products p ON p.id = pv.product_id '
            . "WHERE oi.order_id IN ({$placeholders}) ORDER BY oi.id",
            $ids
        );

        $items = [];
        foreach ($rows as $row) {
            $items[(int) $row['order_id']][] = [
                'variant_id' => (int) $row['variant_id'],
                'product_id' => isset($row['product_id']) ? (int) $row['product_id'] : null,
                'title' => (string) ($row['product_title'] ?? $row['variant_title'] ?? ''),
                'quantity' => (int) $row['quantity'],
                'unit_price_cents' => (int) $row['unit_price_cents'],
            ];
        }

        return array_map(fn (array $order) => [
            'id' => (int) $order['id'],
            'status' => (string) $order['status'],
            'payment_status' => (string) ($order['payment_status'] ?? ''),
            'email' => $order['email'] ?? null,
            'total_cents' => (int) $order['total_cents'],
            'currency' => (string) $order['currency'],
            'created_at' => $order['created_at'] ?? null,
            'items' => $items[(int) $order['id']] ?? [],
        ], $orders);
    }

    /**
     * @param array<int, scalar> $params positional `?` bindings
     * @return array<int, array<string, mixed>>
     */
    private function query(string $sql, array $params = []): array
    {
        $connection = $this->site->cloudflareConnection;
        $client = CloudflareClient::forConnection($connection);

        $payload = $client->post(
            "/accounts/{$connection->account_id}/d1/database/{$this->databaseId()}/query",
            ['sql' => $sql, 'params' => array_values($params)]
        );

        return $payload['result'][0]['results'] ?? [];
    }

    private function databaseId(): ?string
    {
        $id = $this->site->settings['d1_database_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
