<?php

namespace FlareWeber\Deploy;

use FlareWeber\Cloudflare\CloudflareClient;

/**
 * Applies schema + seed SQL to a D1 database through the Cloudflare API.
 * Statements are batched because D1 accepts multi-statement SQL in one
 * execute call but errors out on very large payloads.
 */
class D1Seeder
{
    public function __construct(
        private readonly CloudflareClient $client
    ) {
    }

    public function applySql(string $accountId, string $databaseId, string $sql): array
    {
        $statements = $this->splitStatements($sql);
        $results = [];

        foreach (array_chunk($statements, 50) as $chunk) {
            $response = $this->client->post(
                "/accounts/{$accountId}/d1/database/{$databaseId}/execute",
                ['sql' => implode(";\n", $chunk) . ';']
            );
            $results[] = $response['success'] ?? false;
        }

        return $results;
    }

    /**
     * @return array<int, string>
     */
    private function splitStatements(string $sql): array
    {
        $sql = $this->stripComments($sql);
        $statements = [];
        $buffer = '';
        $inString = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === "'" && ($i === 0 || $sql[$i - 1] !== '\\')) {
                $inString = !$inString;
            }

            if ($char === ';' && !$inString) {
                $stmt = trim($buffer);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            $statements[] = $tail;
        }

        return $statements;
    }

    private function stripComments(string $sql): string
    {
        $lines = [];

        foreach (explode("\n", $sql) as $line) {
            if (str_starts_with(trim($line), '--')) {
                continue;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
