<?php
require_once __DIR__ . '/Vault.php';

// `Maps/projects.canvas` per JSON Canvas 1.0: one group per loop state, stacked down the page, each holding a grid
// of file nodes, one per project note. Ids and positions come from code only; no repo text goes in.
class CanvasExport
{
    public const PATH = 'Maps/projects.canvas';
    private const STATES = ['running', 'stopped', 'stale', 'none', 'unknown'];
    private const COLS = 4;
    private const W = 400;
    private const H = 120;
    private const GAP = 40;
    private const PAD = 40;

    /** @param array<string,array<string,mixed>> $notes note slug => projects row, as ProjectNotes::write returns */
    public static function build(array $notes): array
    {
        $groups = [];
        foreach ($notes as $slug => $row) {
            $state = in_array($row['loop_state'] ?? null, self::STATES, true) ? $row['loop_state'] : 'unknown';
            $groups[$state][] = (string) $slug;
        }
        $nodes = [];
        $y = 0;
        foreach (self::STATES as $state) {
            if (empty($groups[$state])) {
                continue;
            }
            $slugs = $groups[$state];
            sort($slugs);
            $cols = min(self::COLS, count($slugs));
            $rows = (int) ceil(count($slugs) / self::COLS);
            $width = 2 * self::PAD + $cols * self::W + ($cols - 1) * self::GAP;
            $height = 2 * self::PAD + $rows * self::H + ($rows - 1) * self::GAP;
            $nodes[] = ['id' => 'group-' . $state, 'type' => 'group', 'label' => $state,
                'x' => 0, 'y' => $y, 'width' => $width, 'height' => $height];
            foreach ($slugs as $i => $slug) {
                $nodes[] = ['id' => 'project-' . $slug, 'type' => 'file', 'file' => 'Projects/' . $slug . '.md',
                    'x' => self::PAD + ($i % self::COLS) * (self::W + self::GAP),
                    'y' => $y + self::PAD + intdiv($i, self::COLS) * (self::H + self::GAP),
                    'width' => self::W, 'height' => self::H];
            }
            $y += $height + 2 * self::GAP;
        }
        return ['nodes' => $nodes, 'edges' => []];
    }

    public static function write(Vault $vault, array $notes): bool
    {
        $json = json_encode(self::build($notes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $vault->write(self::PATH, $json . "\n");
    }
}
