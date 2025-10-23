-- Create Attributes table in cache database
-- Stores attribute definitions (table.column pairs with data types and tokenization strategy)
DROP TABLE IF EXISTS Attributes;

CREATE TABLE Attributes (
    Aid INTEGER PRIMARY KEY,
    TableName TEXT NOT NULL,
    ColumnName TEXT NOT NULL,
    DataType TEXT NOT NULL,
    TokenStrategy TEXT DEFAULT 'whole',  -- 'whole' or 'split' for text tokenization
    UNIQUE(TableName, ColumnName)
) WITHOUT ROWID;

