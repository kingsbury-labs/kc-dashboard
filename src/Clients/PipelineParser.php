<?php
require_once __DIR__ . '/DashboardParser.php';

// Reads the table under `## Active Leads` in clients/_PIPELINE.md, cleaned the same way as the dashboard.
class PipelineParser
{
    /** @return array<int,array<string,string>> */
    public static function parse(string $md): array
    {
        $leads = [];
        foreach (DashboardParser::table($md, 'Active Leads') as $row) {
            $leads[] = [
                'name' => $row['name'] ?? '',
                'business' => $row['business'] ?? '',
                'town' => $row['town'] ?? '',
                'stage' => $row['stage'] ?? '',
                'price' => $row['intro price'] ?? '',
                'next_action' => $row['next action'] ?? '',
                'notes' => $row['notes'] ?? '',
            ];
        }
        return $leads;
    }
}
