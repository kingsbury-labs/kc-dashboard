<?php
require_once __DIR__ . '/Vault.php';

// Rob's kanban, in the Obsidian Kanban plugin's format. Created once if missing; after that it is Rob's file and
// no run ever writes it again. Lower case because vault file names are `[a-z0-9-]` only.
class Board
{
    public const PATH = 'Boards/rob.md';

    public static function content(): string
    {
        // The plan says `kanban-plugin: board`; `basic` is the value the Obsidian Kanban plugin itself writes for a
        // board, so it is the one the plugin is sure to open.
        return "---\n\nkanban-plugin: basic\n\n---\n\n## Backlog\n\n\n\n## Doing\n\n\n\n## Done\n\n\n";
    }

    public static function ensure(Vault $vault): bool
    {
        return $vault->createOnce(self::PATH, self::content());
    }
}
