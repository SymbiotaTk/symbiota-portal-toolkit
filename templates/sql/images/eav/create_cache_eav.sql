-- Create EAV table in cache database
-- The main Entity-Attribute-Value index table
-- Normalized design: one row per (Eid, Aid, Vid) combination
-- For :split tokenization, multiple rows with VidOrder to preserve token sequence
DROP TABLE IF EXISTS EAV;

CREATE TABLE EAV (
    Eid INTEGER NOT NULL,
    Aid INTEGER NOT NULL,
    Vid INTEGER,             -- Foreign key to ValuesText.Vid (NULL for numeric values)
    VidOrder INTEGER,        -- Order of token for :split attributes (NULL for :whole)
    ValueNumber REAL,        -- For numeric values (NULL for text values)
    FOREIGN KEY (Eid) REFERENCES Entities(Eid),
    FOREIGN KEY (Aid) REFERENCES Attributes(Aid),
    FOREIGN KEY (Vid) REFERENCES ValuesText(Vid),
    CHECK ((Vid IS NULL) != (ValueNumber IS NULL))
);

-- Note: No PRIMARY KEY because Vid and VidOrder can be NULL
-- Uniqueness is enforced by the unique index below

-- Unique indexes to enforce uniqueness (separate for text and numeric)
-- For text values: (Eid, Aid, Vid, VidOrder) must be unique
CREATE UNIQUE INDEX idx_eav_unique_text ON EAV(Eid, Aid, Vid, VidOrder) WHERE Vid IS NOT NULL;

-- For numeric values: (Eid, Aid) must be unique (only one numeric value per entity-attribute)
CREATE UNIQUE INDEX idx_eav_unique_numeric ON EAV(Eid, Aid) WHERE ValueNumber IS NOT NULL;

-- Indexes for fast lookups
CREATE INDEX idx_eav_aid_vid ON EAV(Aid, Vid) WHERE Vid IS NOT NULL;
CREATE INDEX idx_eav_vid ON EAV(Vid) WHERE Vid IS NOT NULL;
CREATE INDEX idx_eav_eid ON EAV(Eid);
CREATE INDEX idx_eav_eid_aid ON EAV(Eid, Aid);
CREATE INDEX idx_eav_valuenumber ON EAV(ValueNumber) WHERE ValueNumber IS NOT NULL;
CREATE INDEX idx_eav_aid_valuenumber ON EAV(Aid, ValueNumber) WHERE ValueNumber IS NOT NULL;

