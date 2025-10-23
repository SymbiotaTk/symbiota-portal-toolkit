# EAV Test Fixtures - 10 Records

This directory contains a minimal 10-record subset of the full EAV fixtures for fast unit testing.

## Contents

- `media.tsv` - 10+ media records (multiple images per occurrence)
- `omoccurrences.tsv` - 10 occurrence records
- `omcollections.tsv` - Related collection records
- `taxa.tsv` - Related taxonomy records

## Usage

These fixtures are designed for fast unit tests that need to verify EAV functionality
without the overhead of building large indexes.

## Generation

Generated from `spec/fixtures/eav/` using `spec/unit/Models/ImagesModelGenerateSmallFixturesSpec.php`