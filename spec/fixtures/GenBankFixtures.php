<?php

namespace Symbiota\Helpers\Spec\Fixtures;

/**
 * Test fixtures for GenBank model testing
 * Provides realistic test data modeled on actual database content
 */
class GenBankFixtures
{
    /**
     * Sample collections data
     */
    public static function getCollections(): array
    {
        return [
            [
                'collid' => 1,
                'institutioncode' => 'ARIZ',
                'collectioncode' => 'ARIZ',
                'collectionname' => 'University of Arizona Herbarium',
                'homepage' => 'https://cals.arizona.edu/herbarium/',
                'contact' => 'curator@arizona.edu',
                'email' => 'curator@arizona.edu',
                'latitude' => 32.2319,
                'longitude' => -110.9501,
                'icon' => null,
                'colltype' => 'Preserved Specimens',
                'managementtype' => 'Live Data',
                'publicedits' => 1,
                'guidtarget' => 'symbiotaUUID',
                'rights' => 'Copyright reserved',
                'rightsholder' => 'University of Arizona',
                'usageterms' => null,
                'publishtogbif' => 1,
                'publishtoidigbio' => 1,
                'aggkeysonly' => 0,
                'collectionguid' => null,
                'securitykey' => null,
                'recordcnt' => 125000,
                'uploaddate' => '2023-01-15',
                'dwcaurl' => null,
                'bibliographiccitation' => 'University of Arizona Herbarium (ARIZ)',
                'accessrights' => 'http://creativecommons.org/licenses/by-nc/4.0/'
            ],
            [
                'collid' => 2,
                'institutioncode' => 'ASU',
                'collectioncode' => 'ASU',
                'collectionname' => 'Arizona State University Vascular Plant Herbarium',
                'homepage' => 'https://sols.asu.edu/research/herbarium',
                'contact' => 'herbarium@asu.edu',
                'email' => 'herbarium@asu.edu',
                'latitude' => 33.4194,
                'longitude' => -111.9339,
                'icon' => null,
                'colltype' => 'Preserved Specimens',
                'managementtype' => 'Live Data',
                'publicedits' => 1,
                'guidtarget' => 'symbiotaUUID',
                'rights' => 'Copyright reserved',
                'rightsholder' => 'Arizona State University',
                'usageterms' => null,
                'publishtogbif' => 1,
                'publishtoidigbio' => 1,
                'aggkeysonly' => 0,
                'collectionguid' => null,
                'securitykey' => null,
                'recordcnt' => 89000,
                'uploaddate' => '2023-02-20',
                'dwcaurl' => null,
                'bibliographiccitation' => 'Arizona State University Vascular Plant Herbarium (ASU)',
                'accessrights' => 'http://creativecommons.org/licenses/by-nc/4.0/'
            ]
        ];
    }

    /**
     * Sample occurrence records for catalog number searches
     */
    public static function getOccurrencesByCatalogNumber(string $catalogNumber, array $collectionIds = []): array
    {
        $allRecords = [
            [
                'occid' => 163172,
                'collid' => 1,
                'catalogNumber' => 'ARIZ123456',
                'otherCatalogNumbers' => null,
                'family' => 'Asteraceae',
                'scientificName' => 'Helianthus annuus L.',
                'scientificNameAuthorship' => 'L.',
                'genus' => 'Helianthus',
                'specificEpithet' => 'annuus',
                'taxonRank' => 'species',
                'infraspecificEpithet' => null,
                'identifiedBy' => 'J. Smith',
                'dateIdentified' => '2023-05-15',
                'identificationRemarks' => null,
                'taxonRemarks' => null,
                'identificationQualifier' => null,
                'typeStatus' => null,
                'recordedBy' => 'M. Johnson',
                'recordNumber' => '1234',
                'eventDate' => '2023-04-20',
                'year' => 2023,
                'month' => 4,
                'day' => 20,
                'startDayOfYear' => 110,
                'endDayOfYear' => null,
                'verbatimEventDate' => '20 April 2023',
                'habitat' => 'Roadside, sandy soil',
                'substrate' => null,
                'fieldNotes' => 'Common along highway',
                'fieldNumber' => null,
                'occurrenceRemarks' => null,
                'informationWithheld' => null,
                'dataGeneralizations' => null,
                'dynamicProperties' => null,
                'associatedCollectors' => 'S. Wilson',
                'associatedTaxa' => null,
                'reproductiveCondition' => 'Flowering',
                'cultivationStatus' => null,
                'establishmentMeans' => 'native',
                'lifeStage' => 'adult',
                'sex' => null,
                'individualCount' => null,
                'samplingProtocol' => null,
                'preparations' => 'pressed',
                'country' => 'United States',
                'stateProvince' => 'Arizona',
                'county' => 'Pima County',
                'municipality' => null,
                'locality' => '5 miles north of Tucson on Highway 77',
                'localitySecurity' => 0,
                'localitySecurityReason' => null,
                'decimalLatitude' => 32.3617,
                'decimalLongitude' => -110.9265,
                'geodeticDatum' => 'WGS84',
                'coordinateUncertaintyInMeters' => 100,
                'footprintWKT' => null,
                'coordinatePrecision' => null,
                'locationRemarks' => null,
                'verbatimCoordinates' => null,
                'verbatimLatitude' => null,
                'verbatimLongitude' => null,
                'verbatimCoordinateSystem' => null,
                'georeferenceBy' => 'GPS',
                'georeferenceProtocol' => null,
                'georeferenceSources' => 'GPS unit',
                'georeferenceVerificationStatus' => null,
                'georeferenceRemarks' => null,
                'minimumElevationInMeters' => 750,
                'maximumElevationInMeters' => null,
                'verbatimElevation' => '750 m',
                'disposition' => null,
                'duplicateQuantity' => null,
                'storageLocation' => null,
                'genericcolumn1' => null,
                'genericcolumn2' => null,
                'modified' => '2023-05-15 14:30:00',
                'language' => 'en',
                'processingstatus' => 'unprocessed',
                'recordEnteredBy' => 'dataentry',
                'duplicateIdentifier' => null,
                'observeruid' => null,
                'dateLastModified' => '2023-05-15 14:30:00',
                'institutioncode' => 'ARIZ',
                'collectioncode' => 'ARIZ',
                'collectionname' => 'University of Arizona Herbarium'
            ]
        ];

        // Filter by catalog number
        $filtered = array_filter($allRecords, function($record) use ($catalogNumber) {
            return $record['catalogNumber'] === $catalogNumber;
        });

        // Filter by collection IDs if provided
        if (!empty($collectionIds)) {
            $filtered = array_filter($filtered, function($record) use ($collectionIds) {
                return in_array($record['collid'], $collectionIds);
            });
        }

        return array_values($filtered);
    }

    /**
     * Sample occurrence records for occid searches
     */
    public static function getOccurrenceByOccid(int $occid): ?array
    {
        $records = self::getOccurrencesByCatalogNumber('ARIZ123456');
        
        foreach ($records as $record) {
            if ($record['occid'] === $occid) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Sample search results with pagination
     */
    public static function getSearchResults(array $params = []): array
    {
        $limit = $params['limit'] ?? 20;
        $offset = $params['offset'] ?? 0;
        
        $allResults = self::getOccurrencesByCatalogNumber('ARIZ123456');
        
        return [
            'results' => array_slice($allResults, $offset, $limit),
            'total' => count($allResults),
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < count($allResults)
        ];
    }

    /**
     * Sample error responses
     */
    public static function getDatabaseUnavailableResponse(): array
    {
        return [
            'type' => 'error',
            'message' => 'Database unavailable',
            'status_code' => 503,
            'operation' => 'database_check',
            'timestamp' => date('c')
        ];
    }

    /**
     * Sample collection selection error
     */
    public static function getCollectionSelectionError(): array
    {
        return [
            'type' => 'error',
            'message' => 'Collection selection required for catalog number searches',
            'status_code' => 400,
            'operation' => 'catalogNumber',
            'timestamp' => date('c')
        ];
    }

    /**
     * Sample validation errors
     */
    public static function getValidationError(string $field, string $message): array
    {
        return [
            'type' => 'error',
            'message' => sprintf('%s validation failed: %s', ucfirst($field), $message),
            'status_code' => 400,
            'operation' => 'validation',
            'field' => $field,
            'timestamp' => date('c')
        ];
    }

    /**
     * Sample help content
     */
    public static function getHelpContent(): string
    {
        return <<<'HELP'
GenBank Model Help

USAGE:
    {entry_file} genbank [action] [parameters]

ACTIONS:
    index                   - Show main interface
    catalogNumber <number>  - Search by catalog number
    occid <id>             - Search by occurrence ID
    collections            - List available collections
    help                   - Show this help

PARAMETERS:
    --collid=<ids>         - Collection IDs (comma-separated)
    --sort=<field>         - Sort field (catalogNumber, scientificName, etc.)
    --order=<direction>    - Sort direction (asc, desc)
    --limit=<number>       - Results per page (default: 20)
    --offset=<number>      - Results offset (default: 0)

EXAMPLES:
    {entry_file} genbank catalogNumber ARIZ123456 --collid=1,2
    {entry_file} genbank occid 163172
    {entry_file} genbank collections --sort=collectionname
HELP;
    }
}
