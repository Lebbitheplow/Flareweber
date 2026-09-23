<?php

namespace FlareWeber\Deploy;

use FlareWeber\Cloudflare\CloudflareClient;
use RuntimeException;

/**
 * Applies schema + seed SQL to a D1 database through the Cloudflare API
 * (POST .../d1/database/{id}/query). Statements are sent in batches of at
 * most 100 because D1 accepts multi-statement SQL but rejects huge payloads.
 */
class D1Seeder
{
    public const BATCH_SIZE = 100;

    public function __construct(
        private readonly CloudflareClient $client
    ) {
    }

    /** @return int number of statements applied */
    public function applySql(string $accountId, string $databaseId, string $sql): int
    {
        $statements = self::splitStatements($sql);
        $batch = [];
        $count = 0;

        foreach ($statements as $statement) {
            // Schema upgrades: run alone and tolerate "already applied".
            if (preg_match('/^\s*ALTER\s+TABLE\b[\s\S]*\bADD\s+(COLUMN\s+)?/i', $statement) === 1) {
                $this->flush($accountId, $databaseId, $batch, $count);
                $this->execute($accountId, $databaseId, [$statement], tolerateDuplicate: true);
                $count++;

                continue;
            }

            $batch[] = $statement;

            if (count($batch) >= self::BATCH_SIZE) {
                $this->flush($accountId, $databaseId, $batch, $count);
            }
        }

        $this->flush($accountId, $databaseId, $batch, $count);

        return $count;
    }

    /**
     * Apply worker/migrations/*.sql in filename order, once each, recording
     * them in schema_migrations (created by schema.sql). Migrations contain
     * ALTER TABLE statements so they are never re-run once recorded.
     *
     * @return array<int, string> names applied in this run
     */
    public function applyMigrations(string $accountId, string $databaseId, string $dir): array
    {
        $files = is_dir($dir) ? (glob(rtrim($dir, '/\\') . '/*.sql') ?: []) : [];
        sort($files, SORT_STRING);

        if ($files === []) {
            return [];
        }

        $applied = $this->appliedMigrations($accountId, $databaseId);
        $ran = [];

        foreach ($files as $file) {
            $name = basename($file);

            if (in_array($name, $applied, true)) {
                continue;
            }

            foreach (self::splitStatements((string) file_get_contents($file)) as $statement) {
                $alter = preg_match('/^\s*ALTER\s+TABLE\b/i', $statement) === 1;
                $this->execute($accountId, $databaseId, [$statement], tolerateDuplicate: $alter);
            }

            $this->execute($accountId, $databaseId, [
                "INSERT OR IGNORE INTO schema_migrations (name) VALUES ('" . str_replace("'", "''", $name) . "')",
            ]);

            $ran[] = $name;
        }

        return $ran;
    }

    /** @return array<int, string> */
    private function appliedMigrations(string $accountId, string $databaseId): array
    {
        $response = $this->client->post(
            "/accounts/{$accountId}/d1/database/{$databaseId}/query",
            ['sql' => 'SELECT name FROM schema_migrations'],
            throwOnError: false
        );

        if (!($response['success'] ?? false)) {
            return [];
        }

        $names = [];
        foreach ($response['result'][0]['results'] ?? [] as $row) {
            if (is_array($row) && isset($row['name'])) {
                $names[] = (string) $row['name'];
            }
        }

        return $names;
    }

    /** @param array<int, string> $batch */
    private function flush(string $accountId, string $databaseId, array &$batch, int &$count): void
    {
        if ($batch === []) {
            return;
        }

        $this->execute($accountId, $databaseId, $batch);
        $count += count($batch);
        $batch = [];
    }

    /** @param array<int, string> $statements */
    private function execute(string $accountId, string $databaseId, array $statements, bool $tolerateDuplicate = false): void
    {
        $response = $this->client->post(
            "/accounts/{$accountId}/d1/database/{$databaseId}/query",
            ['sql' => implode(";\n", $statements) . ';'],
            throwOnError: false
        );

        if ($response['success'] ?? false) {
            return;
        }

        $messages = [];
        foreach ($response['errors'] ?? [] as $error) {
            $messages[] = is_array($error) ? (string) ($error['message'] ?? 'unknown') : (string) $error;
        }
        $message = $messages !== [] ? implode('; ', $messages) : 'no response from D1';

        // A partially applied ALTER (column already added or renamed) is not fatal.
        if ($tolerateDuplicate && preg_match('/duplicate column|no such column/i', $message) === 1) {
            return;
        }

        throw new RuntimeException('D1 query failed: ' . $message);
    }

    /**
     * Split SQL text into statements on top-level semicolons. Respects single
     * quoted strings with '' escaping, double quoted identifiers, line
     * comments (only outside strings) and block comments.
     *
     * @return array<int, string>
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($char === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end + 1;
                $buffer .= "\n";

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                $buffer .= ' ';

                continue;
            }

            if ($char === "'" || $char === '"') {
                $end = self::closingQuote($sql, $i, $char);
                $buffer .= substr($sql, $i, $end - $i + 1);
                $i = $end + 1;

                continue;
            }

            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                $i++;

                continue;
            }

            $buffer .= $char;
            $i++;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    /**
     * Index of the quote that closes the literal opening at $start. A doubled
     * quote inside the literal is an escaped quote, not a terminator.
     */
    private static function closingQuote(string $sql, int $start, string $quote): int
    {
        $length = strlen($sql);
        $i = $start + 1;

        while ($i < $length) {
            if ($sql[$i] === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $i += 2;

                    continue;
                }

                return $i;
            }

            $i++;
        }

        return $length - 1; // unterminated literal: swallow the rest
    }
}
