-- Get list of searchable fields from Attributes table
-- Used by: ImagesModelHybrid::autocompleteFields()
-- Returns: FieldName, DataType, TokenStrategy
SELECT DISTINCT
    ColumnName as FieldName,
    DataType,
    TokenStrategy
FROM Attributes
ORDER BY FieldName ASC

