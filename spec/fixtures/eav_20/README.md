# EAV Test Fixtures - 20 Records

This directory contains a 20-record subset of the full EAV fixtures for unit testing.

## Contents

- `media.tsv` - 20+ media records (multiple images per occurrence)
- `omoccurrences.tsv` - 20 occurrence records
- `omcollections.tsv` - Related collection records
- `taxa.tsv` - Related taxonomy records

## Usage

These fixtures are designed for unit tests that need more data variation than the
10-record subset but still want fast execution.

## Generation

Generated from `spec/fixtures/eav/` using `spec/unit/Models/ImagesModelGenerateSmallFixturesSpec.php`