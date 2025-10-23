-- Create indexes on EAV cache tables for optimal query performance

-- Index on Entities.EntityValue (already UNIQUE, but explicit index helps)
CREATE INDEX IF NOT EXISTS idx_entities_value ON Entities(EntityValue);

-- Index on Attributes for lookups by table/column
CREATE INDEX IF NOT EXISTS idx_attributes_table ON Attributes(TableName);
CREATE INDEX IF NOT EXISTS idx_attributes_column ON Attributes(ColumnName);

-- Index on ValuesText.ValueText (already UNIQUE, but explicit index helps)
CREATE INDEX IF NOT EXISTS idx_valuestext_value ON ValuesText(ValueText);

-- Indexes on EAV table for fast lookups
-- Note: Indexes are already created in create_cache_eav.sql
-- This file is kept for backwards compatibility but doesn't create duplicate indexes

