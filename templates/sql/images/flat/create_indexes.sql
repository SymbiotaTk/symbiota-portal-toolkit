-- ============================================================================
-- Create Indexes for Flat Index
-- ============================================================================
-- This template creates comprehensive indexes on all searchable fields.
-- Indexes are created based on the configuration in index_config.ini.
--
-- Index naming convention: idx_{table}_{column}
-- ============================================================================

-- Media table indexes
CREATE INDEX IF NOT EXISTS idx_media_mediaID ON media(mediaID);
CREATE INDEX IF NOT EXISTS idx_media_occid ON media(occid);

-- Omoccurrences table indexes (foreign keys)
CREATE INDEX IF NOT EXISTS idx_occ_occid ON omoccurrences(occid);
CREATE INDEX IF NOT EXISTS idx_occ_collid ON omoccurrences(collid);
CREATE INDEX IF NOT EXISTS idx_occ_tidinterpreted ON omoccurrences(tidinterpreted);

-- Taxonomy indexes (for taxon: search across 3 sciname fields + family + genus)
CREATE INDEX IF NOT EXISTS idx_occ_family ON omoccurrences(family COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_occ_genus ON omoccurrences(genus COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_occ_sciname ON omoccurrences(sciname COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_occ_scientificname ON omoccurrences(scientificname COLLATE NOCASE);

-- Concatenated taxon index for fast multi-field taxon searches
-- This allows LIKE searches on concatenated taxon fields to use an index
CREATE INDEX IF NOT EXISTS idx_occ_taxon_concat ON omoccurrences(
    (COALESCE(family, '') || '|' || COALESCE(genus, '') || '|' ||
     COALESCE(sciname, '') || '|' || COALESCE(scientificname, '')) COLLATE NOCASE
);

-- Geography indexes
CREATE INDEX IF NOT EXISTS idx_occ_country ON omoccurrences(country COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_occ_stateProvince ON omoccurrences(stateProvince COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_occ_county ON omoccurrences(county COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_occ_locality ON omoccurrences(locality COLLATE NOCASE);

-- Identifier indexes
CREATE INDEX IF NOT EXISTS idx_occ_catalogNumber ON omoccurrences(catalogNumber COLLATE NOCASE);

-- Collection table indexes
CREATE INDEX IF NOT EXISTS idx_coll_collid ON omcollections(collid);
CREATE INDEX IF NOT EXISTS idx_coll_collectionCode ON omcollections(collectionCode COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_coll_institutionCode ON omcollections(institutionCode COLLATE NOCASE);
CREATE INDEX IF NOT EXISTS idx_coll_collectionName ON omcollections(collectionName COLLATE NOCASE);

-- Taxa table indexes
CREATE INDEX IF NOT EXISTS idx_taxa_tid ON taxa(tid);
CREATE INDEX IF NOT EXISTS idx_taxa_sciname ON taxa(sciname COLLATE NOCASE);

