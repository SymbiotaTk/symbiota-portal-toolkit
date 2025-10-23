# MySQL Direct Query Templates for Hybrid Search

## Overview

These SQL templates are used by the Hybrid Search System to execute optimized queries directly against the MySQL source database for large datasets.

## Template Syntax

Templates use `{placeholder}` syntax for variable interpolation:

```sql
SELECT * FROM media WHERE mediaID = {media_id}
```

**Important**: Use `{variable}` format, NOT `%(variable)s` format.

## Available Templates

### 1. search_by_field.sql
Execute field-specific search (e.g., `family:Liceaceae`)

**Placeholders**:
- `{joins}` - Dynamic JOIN clauses based on field location
- `{field_condition}` - WHERE condition for the specific field
- `{limit}` - Result limit
- `{offset}` - Result offset

### 2. search_freetext.sql
Execute freetext search across all indexed fields

**Placeholders**:
- `{search}` - Search pattern (with wildcards)
- `{limit}` - Result limit
- `{offset}` - Result offset

### 3. search_multi_and.sql
Execute multi-field AND query (all conditions must match)

**Placeholders**:
- `{joins}` - Dynamic JOIN clauses
- `{conditions}` - WHERE conditions joined with AND
- `{limit}` - Result limit
- `{offset}` - Result offset

### 4. search_multi_or.sql
Execute multi-field OR query (any condition can match)

**Placeholders**:
- `{joins}` - Dynamic JOIN clauses
- `{conditions}` - WHERE conditions joined with OR
- `{limit}` - Result limit
- `{offset}` - Result offset

### 5. count_entities.sql
Count total entities (media records with occid)

**Placeholders**: None

### 6. get_autocomplete_values.sql
Get distinct values for autocomplete (used during cache build)

**Placeholders**:
- `{table}` - Table name
- `{column}` - Column name
- `{joins}` - JOIN clauses if column is in related table

## Field-to-Table Mapping

The query builder uses this mapping to determine which JOINs are needed:

| Field | Table | JOIN Path |
|-------|-------|-----------|
| mediaID | media | (root) |
| url, originalUrl, thumbnailUrl | media | (root) |
| occid | omoccurrences | media → omoccurrences |
| catalogNumber, otherCatalogNumbers | omoccurrences | media → omoccurrences |
| family, genus, scientificName, sciname | omoccurrences | media → omoccurrences |
| continent, country, stateProvince, county, municipality, locality | omoccurrences | media → omoccurrences |
| decimalLatitude, decimalLongitude | omoccurrences | media → omoccurrences |
| eventDate, dateLastModified | omoccurrences | media → omoccurrences |
| collid | omoccurrences | media → omoccurrences |
| tidInterpreted | omoccurrences | media → omoccurrences |
| collectionName, collectionCode, institutionCode | omcollections | media → omoccurrences → omcollections |
| sciName | taxa | media → omoccurrences → taxa |

## Query Optimization

### Indexes Required

For optimal performance, the source MySQL database should have these indexes:

```sql
-- media table
CREATE INDEX idx_media_occid ON media(occid);

-- omoccurrences table
CREATE INDEX idx_occ_collid ON omoccurrences(collid);
CREATE INDEX idx_occ_tid ON omoccurrences(tidInterpreted);
CREATE INDEX idx_occ_family ON omoccurrences(family);
CREATE INDEX idx_occ_genus ON omoccurrences(genus);
CREATE INDEX idx_occ_sciname ON omoccurrences(scientificName);
CREATE INDEX idx_occ_country ON omoccurrences(country);
CREATE INDEX idx_occ_state ON omoccurrences(stateProvince);
CREATE INDEX idx_occ_eventdate ON omoccurrences(eventDate);

-- omcollections table
CREATE INDEX idx_coll_code ON omcollections(collectionCode);
CREATE INDEX idx_coll_name ON omcollections(collectionName);

-- taxa table
CREATE INDEX idx_taxa_sciname ON taxa(sciName);
```

**Note**: Since we cannot modify the source database, the system will work without these indexes but may be slower. The hybrid system is still faster than large SQLite EAV cache even without optimal MySQL indexes.

### Query Patterns

#### Pattern 1: Field in Root Table (media)
```sql
-- No JOINs needed
SELECT DISTINCT m.mediaID, m.url, m.thumbnailUrl
FROM media m
WHERE m.occid IS NOT NULL
AND m.{field} LIKE {value}
LIMIT {limit} OFFSET {offset}
```

#### Pattern 2: Field in omoccurrences
```sql
-- Single JOIN
SELECT DISTINCT m.mediaID, m.url, m.thumbnailUrl
FROM media m
LEFT JOIN omoccurrences o ON m.occid = o.occid
WHERE m.occid IS NOT NULL
AND o.{field} LIKE {value}
LIMIT {limit} OFFSET {offset}
```

#### Pattern 3: Field in omcollections
```sql
-- Two JOINs
SELECT DISTINCT m.mediaID, m.url, m.thumbnailUrl
FROM media m
LEFT JOIN omoccurrences o ON m.occid = o.occid
LEFT JOIN omcollections c ON o.collid = c.collID
WHERE m.occid IS NOT NULL
AND c.{field} LIKE {value}
LIMIT {limit} OFFSET {offset}
```

#### Pattern 4: Field in taxa
```sql
-- Two JOINs
SELECT DISTINCT m.mediaID, m.url, m.thumbnailUrl
FROM media m
LEFT JOIN omoccurrences o ON m.occid = o.occid
LEFT JOIN taxa t ON o.tidInterpreted = t.tid
WHERE m.occid IS NOT NULL
AND t.{field} LIKE {value}
LIMIT {limit} OFFSET {offset}
```

## Performance Expectations

With proper MySQL indexes:
- Field-specific search: 0.1-0.5s
- Freetext search: 0.5-2s
- Multi-field AND: 0.2-1s
- Multi-field OR: 0.5-2s

Without indexes (worst case):
- Field-specific search: 1-5s
- Freetext search: 5-15s
- Multi-field AND: 2-10s
- Multi-field OR: 5-15s

Still much faster than 60s+ for large SQLite EAV cache!

## Usage Example

```php
use Symbiota\Helpers\Core\SqlTemplateParser;

$parser = new SqlTemplateParser();

// Load template
$template = $parser->parse('images/mysql/search_by_field.sql');

// Interpolate variables
$sql = $parser->interpolate($template, [
    'joins' => 'LEFT JOIN omoccurrences o ON m.occid = o.occid',
    'field_condition' => "LOWER(o.family) LIKE 'liceaceae%'",
    'limit' => 100,
    'offset' => 0
]);

// Execute query
$stmt = $db->prepare($sql);
$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

