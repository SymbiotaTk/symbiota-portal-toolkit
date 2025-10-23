select CollID,
       CollectionName,
       `InstitutionCode`,
       CollectionCode,
       fulldescription,
       contactJson,
       CollType,
       ManagementType,
       dwcaUrl,
       initialTimestamp,
       (SELECT max(distinct(dateLastModified)) FROM omoccurrences
          WHERE collid = {collid})
       AS dateLastModified,
       (SELECT count(occid) FROM omoccurrences
          WHERE collid = {collid}) AS totalRecords,
       (SELECT count(occid) FROM omoccurrences
          WHERE collid = {collid}
          AND tidinterpreted IN (SELECT TID FROM taxa
              WHERE TID = tidinterpreted)
       )
       AS totalRecordsTaxonIdentified,
       (SELECT count(distinct(tidinterpreted)) FROM omoccurrences
          WHERE collid = {collid}
          AND tidinterpreted IN (SELECT TID FROM taxa
              WHERE TID = tidinterpreted)
       )
       AS totalUniqueTaxonIdentified,
       (SELECT count(occid) FROM omoccurrences
          WHERE collid = {collid}
          AND tidinterpreted NOT IN (SELECT TID FROM taxa
              WHERE TID = tidinterpreted)
       )
       AS totalRecordsTaxonNotIdentified,
       (SELECT count(distinct(SciName)) FROM omoccurrences
          WHERE collid = {collid}
          AND tidinterpreted NOT IN (SELECT TID FROM taxa
              WHERE TID = tidinterpreted)
       )
       AS totalUniqueTaxonNotIdentified,
       (SELECT count(distinct(SciName)) FROM omoccurrences
          WHERE collid = {collid}
          AND tidinterpreted NOT IN (SELECT TID FROM taxa
              WHERE TID = tidinterpreted)
          AND sciname IN (SELECT SciName FROM taxa
              WHERE SciName = TRIM(sciname))
       )
       AS totalUniqueTaxonNotIdentifiedExistsInThesaurus,
       (SELECT count(distinct(SciName)) FROM omoccurrences
          WHERE collid = {collid}
          AND tidinterpreted NOT IN (SELECT TID FROM taxa
              WHERE TID = tidinterpreted)
          AND sciname NOT IN (SELECT SciName FROM taxa
              WHERE SciName = TRIM(sciname))
          AND (TRIM(sciname) != ''
                  OR sciname != 'NULL'
                  OR sciname IS NOT NULL)
       )
       AS totalUniqueTaxonNotIdentifiedUnrecognizedNotNULL,
       (SELECT count(occid) FROM omoccurrences
          WHERE collid = {collid}
          AND (TRIM(sciname) = ''
                  OR sciname = 'NULL'
                  OR sciname IS NULL)
          AND tidinterpreted NOT IN (SELECT TID FROM taxa
              WHERE TID = tidinterpreted)
       )
       AS totalRecordsTaxonNULL,
       (SELECT GROUP_CONCAT(distinct(SciName) SEPARATOR ' | ') AS taxon FROM omoccurrences
          WHERE collid = {collid}
          AND tidinterpreted NOT IN (SELECT TID FROM taxa
              WHERE TID = tidinterpreted)
          AND sciname NOT IN (SELECT SciName FROM taxa
              WHERE SciName = TRIM(sciname))
          AND (TRIM(sciname) != ''
                  OR sciname != 'NULL'
                  OR sciname IS NOT NULL)
          ORDER BY taxon ASC
       )
       AS taxaNotRecognizedByThesaurus
FROM omcollections WHERE collid = {collid};
