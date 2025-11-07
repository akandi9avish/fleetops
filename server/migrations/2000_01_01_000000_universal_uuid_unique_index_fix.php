<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * RAILWAY PATCH: Universal fix for ALL uuid columns that need UNIQUE constraints
     *
     * This migration scans the entire database and ensures that ANY uuid column
     * that is referenced by a foreign key has a UNIQUE constraint.
     *
     * This prevents the error:
     * "Failed to add the foreign key constraint. Missing unique key for constraint..."
     *
     * @return void
     */
    public function up(): void
    {
        echo "\n🔍 UNIVERSAL UUID UNIQUE INDEX FIX\n";
        echo "====================================\n\n";

        // CRITICAL FIX: Explicitly ensure telematics.uuid has UNIQUE constraint
        // This is needed because assets migration will reference it, but assets doesn't exist yet
        // so the FK scan below won't detect it
        if (Schema::hasTable('telematics') && Schema::hasColumn('telematics', 'uuid')) {
            echo "🔧 Checking telematics.uuid for UNIQUE constraint...\n";

            $hasUnique = DB::select("
                SELECT COUNT(*) as count
                FROM information_schema.TABLE_CONSTRAINTS tc
                JOIN information_schema.KEY_COLUMN_USAGE kcu
                    ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                    AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
                WHERE tc.TABLE_SCHEMA = DATABASE()
                AND tc.TABLE_NAME = 'telematics'
                AND kcu.COLUMN_NAME = 'uuid'
                AND tc.CONSTRAINT_TYPE IN ('PRIMARY KEY', 'UNIQUE')
            ");

            if ($hasUnique[0]->count == 0) {
                echo "⚠️  telematics.uuid lacks UNIQUE constraint - adding now...\n";

                // Drop any existing non-unique indexes first
                $indexes = DB::select("SHOW INDEX FROM telematics WHERE Column_name = 'uuid' AND Key_name != 'PRIMARY' AND Non_unique = 1");
                foreach ($indexes as $index) {
                    try {
                        DB::statement("DROP INDEX {$index->Key_name} ON telematics");
                        echo "   Dropped non-unique index: {$index->Key_name}\n";
                    } catch (\Exception $e) {
                        echo "   Warning: Could not drop index: {$e->getMessage()}\n";
                    }
                }

                // Add UNIQUE constraint
                try {
                    DB::statement("ALTER TABLE telematics ADD UNIQUE INDEX telematics_uuid_unique (uuid)");
                    echo "   ✅ Added UNIQUE constraint to telematics.uuid\n";
                } catch (\Exception $e) {
                    echo "   ❌ CRITICAL: Could not add UNIQUE to telematics.uuid: {$e->getMessage()}\n";
                    throw $e;
                }
            } else {
                echo "✅ telematics.uuid already has UNIQUE constraint\n";
            }
        }

        echo "\n";

        // Get all foreign key relationships in the database
        $foreignKeys = DB::select("
            SELECT
                TABLE_NAME as child_table,
                COLUMN_NAME as child_column,
                REFERENCED_TABLE_NAME as parent_table,
                REFERENCED_COLUMN_NAME as parent_column,
                CONSTRAINT_NAME as fk_name
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_NAME IS NOT NULL
            AND REFERENCED_COLUMN_NAME IS NOT NULL
        ");

        $tablesFixed = [];
        $alreadyUnique = [];

        foreach ($foreignKeys as $fk) {
            $parentTable = $fk->parent_table;
            $parentColumn = $fk->parent_column;

            // Skip if we've already processed this table.column combination
            $key = "{$parentTable}.{$parentColumn}";
            if (in_array($key, $tablesFixed) || in_array($key, $alreadyUnique)) {
                continue;
            }

            // Check if this column exists and is a uuid/char(36) type
            $columnInfo = DB::select("
                SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = ?
                AND COLUMN_NAME = ?
            ", [$parentTable, $parentColumn]);

            if (empty($columnInfo)) {
                continue;
            }

            $column = $columnInfo[0];

            // Only process uuid-like columns (char(36), varchar(36), or columns named 'uuid' or '*_uuid')
            $isUuidLike = (
                strpos($column->COLUMN_TYPE, 'char(36)') !== false ||
                strpos($column->COLUMN_TYPE, 'varchar(36)') !== false ||
                $parentColumn === 'uuid' ||
                strpos($parentColumn, '_uuid') !== false
            );

            if (!$isUuidLike) {
                continue;
            }

            // Check if column already has PRIMARY KEY or UNIQUE constraint
            $uniqueIndexes = DB::select("
                SELECT tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE
                FROM information_schema.TABLE_CONSTRAINTS tc
                JOIN information_schema.KEY_COLUMN_USAGE kcu
                    ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                    AND tc.TABLE_SCHEMA = kcu.TABLE_SCHEMA
                    AND tc.TABLE_NAME = kcu.TABLE_NAME
                WHERE tc.TABLE_SCHEMA = DATABASE()
                AND tc.TABLE_NAME = ?
                AND kcu.COLUMN_NAME = ?
                AND tc.CONSTRAINT_TYPE IN ('PRIMARY KEY', 'UNIQUE')
            ", [$parentTable, $parentColumn]);

            if (!empty($uniqueIndexes)) {
                $alreadyUnique[] = $key;
                echo "✅ {$key} already has UNIQUE/PRIMARY constraint\n";
                continue;
            }

            // This column is referenced by FK but doesn't have UNIQUE constraint!
            echo "⚠️  {$key} is referenced by FK but lacks UNIQUE constraint\n";
            echo "   Referenced by: {$fk->child_table}.{$fk->child_column}\n";

            // Drop any existing non-unique indexes on this column
            $existingIndexes = DB::select("
                SHOW INDEX FROM {$parentTable}
                WHERE Column_name = ?
                AND Key_name != 'PRIMARY'
                AND Non_unique = 1
            ", [$parentColumn]);

            foreach ($existingIndexes as $index) {
                try {
                    DB::statement("DROP INDEX {$index->Key_name} ON {$parentTable}");
                    echo "   Dropped non-unique index: {$index->Key_name}\n";
                } catch (\Exception $e) {
                    echo "   Warning: Could not drop index {$index->Key_name}: {$e->getMessage()}\n";
                }
            }

            // Add UNIQUE constraint
            $uniqueIndexName = "{$parentTable}_{$parentColumn}_unique";
            try {
                DB::statement("ALTER TABLE {$parentTable} ADD UNIQUE INDEX {$uniqueIndexName} ({$parentColumn})");
                echo "   ✅ Added UNIQUE index: {$uniqueIndexName}\n";
                $tablesFixed[] = $key;
            } catch (\Exception $e) {
                echo "   ❌ CRITICAL: Could not add UNIQUE index: {$e->getMessage()}\n";
                // Don't throw - continue to fix other tables
            }
        }

        echo "\n====================================\n";
        echo "Summary:\n";
        echo "- Already unique: " . count($alreadyUnique) . " columns\n";
        echo "- Fixed: " . count($tablesFixed) . " columns\n";
        echo "====================================\n\n";

        if (count($tablesFixed) > 0) {
            echo "✅ Universal UUID fix completed successfully!\n\n";
        } else {
            echo "ℹ️  No uuid columns needed fixing.\n\n";
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        // Cannot reverse - we don't know which unique indexes we added
        echo "⚠️  Cannot reverse universal uuid unique index fix\n";
    }
};
