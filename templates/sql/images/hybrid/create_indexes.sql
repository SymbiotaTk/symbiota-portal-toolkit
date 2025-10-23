-- Comprehensive indexes for hybrid index performance

-- Entities indexes
CREATE INDEX IF NOT EXISTS idx_entities_occid ON Entities(occid);
CREATE INDEX IF NOT EXISTS idx_entities_mediaid ON Entities(mediaID);

-- Collections indexes
CREATE INDEX IF NOT EXISTS idx_collections_collid ON Collections(collid);
CREATE INDEX IF NOT EXISTS idx_collections_name ON Collections(collectionName COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_collections_code ON Collections(collectionCode COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_collections_institution ON Collections(institutionCode COLLATE NOCASE);

-- Attributes indexes (standardized schema uses ColumnName)
CREATE INDEX IF NOT EXISTS idx_attributes_columnname ON Attributes(ColumnName);

-- ValuesText indexes
CREATE INDEX IF NOT EXISTS idx_valuestext_text ON ValuesText(ValueText COLLATE NOCASE);

-- EAV indexes (critical for performance)
CREATE INDEX IF NOT EXISTS idx_eav_eid ON EAV(Eid);
CREATE INDEX IF NOT EXISTS idx_eav_aid ON EAV(Aid);
CREATE INDEX IF NOT EXISTS idx_eav_vid ON EAV(Vid);
CREATE INDEX IF NOT EXISTS idx_eav_eid_aid ON EAV(Eid, Aid);
CREATE INDEX IF NOT EXISTS idx_eav_aid_vid ON EAV(Aid, Vid);
CREATE INDEX IF NOT EXISTS idx_eav_valuenumber ON EAV(ValueNumber) WHERE ValueNumber IS NOT NULL;

