-- Insert split words into EAV with normalized schema
-- One row per (Eid, Aid, Vid) with VidOrder to preserve token sequence
INSERT INTO cache.EAV (Eid, Aid, Vid, VidOrder, ValueNumber)
SELECT
    sw.Eid,
    {AID} AS Aid,
    vt.Vid,
    sw.WordOrder,
    NULL AS ValueNumber
FROM TempSplitWords sw
JOIN cache.ValuesText vt ON sw.Word = vt.ValueText
ORDER BY sw.Eid, sw.WordOrder;

