# Symbiota Portal Toolkit - Development Scripts

This directory contains helper scripts for development, testing, and code maintenance.

## Docker Test Runner

**File**: `docker-test.sh`

Run Kahlan tests inside Docker container for consistent test environment.

### Usage

```bash
# Run all tests
./scripts/docker-test.sh

# Run unit tests only
./scripts/docker-test.sh unit

# Run integration tests only
./scripts/docker-test.sh integration

# Run specific test file
./scripts/docker-test.sh spec/unit/Models/ImagesModelSpec.php

# Run with verbose output
./scripts/docker-test.sh --verbose

# Run with coverage report
./scripts/docker-test.sh --coverage
```

### Configuration

Set `DOCKER_CONTAINER` environment variable to use a different container:

```bash
export DOCKER_CONTAINER=my-container-name
./scripts/docker-test.sh
```

---

## Docker Inventory Runner

**File**: `docker-inventory.sh`

Run code inventory analysis inside Docker container to identify unused code, check test coverage, and optimize the codebase.

### Usage

```bash
# Analyze all models
./scripts/docker-inventory.sh analyze

# Analyze specific model
./scripts/docker-inventory.sh analyze --model=images

# Find unused code
./scripts/docker-inventory.sh unused

# Find unused code in specific model
./scripts/docker-inventory.sh unused --model=images

# Check test coverage
./scripts/docker-inventory.sh coverage

# Show only untested functionality
./scripts/docker-inventory.sh coverage --missing

# List SQL templates
./scripts/docker-inventory.sh sql-templates

# Show only unused SQL templates
./scripts/docker-inventory.sh sql-templates --unused

# Run full cleanup workflow
./scripts/docker-inventory.sh cleanup

# Create removal plan for unused code
./scripts/docker-inventory.sh create-plan
./scripts/docker-inventory.sh create-plan images
```

### Full Cleanup Workflow

The `cleanup` command runs a complete analysis:

```bash
./scripts/docker-inventory.sh cleanup
```

This will:
1. Analyze codebase for unused code
2. Identify unused methods and SQL templates
3. Check test coverage
4. List untested functionality

After review, create a removal plan:

```bash
./scripts/docker-inventory.sh create-plan images > cleanup.sh
# Review cleanup.sh
# Execute if safe
bash cleanup.sh
```

---

## TDD Development Workflow

### Red-Green-Refactor Cycle

```bash
# 1. Write failing test
# Edit spec/unit/Models/SearchBackendSpec.php

# 2. Run test (should fail - RED)
./scripts/docker-test.sh spec/unit/Models/SearchBackendSpec.php

# 3. Write minimal code to pass test
# Edit src/Models/SearchBackendInterface.php

# 4. Run test (should pass - GREEN)
./scripts/docker-test.sh spec/unit/Models/SearchBackendSpec.php

# 5. Refactor (optimize, clean up)
./scripts/docker-inventory.sh unused --model=images

# 6. Run all tests (verify no regressions)
./scripts/docker-test.sh

# 7. Check coverage
./scripts/docker-inventory.sh coverage --missing

# 8. Repeat
```

### Daily Development Routine

```bash
# Morning: Check codebase health
./scripts/docker-inventory.sh analyze --model=images
./scripts/docker-test.sh

# During development: TDD cycle
# (see above)

# Before commit: Full verification
./scripts/docker-test.sh --verbose
./scripts/docker-inventory.sh coverage --missing
./scripts/docker-inventory.sh unused --model=images
```

---

## Code Cleanup Best Practices

### Safe Cleanup Process

1. **Identify unused code**:
   ```bash
   ./scripts/docker-inventory.sh unused --model=images
   ```

2. **Create removal plan**:
   ```bash
   ./scripts/docker-inventory.sh create-plan images > cleanup.sh
   ```

3. **Review plan** (manually inspect `cleanup.sh`)

4. **Rename files to .REMOVE** (don't delete yet):
   ```bash
   # Execute cleanup.sh if safe
   bash cleanup.sh
   ```

5. **Run tests**:
   ```bash
   ./scripts/docker-test.sh
   ```

6. **Test CLI commands manually**:
   ```bash
   docker exec symbiota-web bash -c "cd /var/www/html/portal/tk && php index.php images help"
   ```

7. **Delete .REMOVE files** (only after verification):
   ```bash
   docker exec symbiota-web bash -c "cd /var/www/html/portal/tk && find . -name '*.REMOVE' -delete"
   ```

### Never Delete Without Testing

❌ **DON'T**: Delete files immediately  
✅ **DO**: Rename to `.REMOVE`, test, then delete

❌ **DON'T**: Trust automated analysis blindly  
✅ **DO**: Manually review removal plans

❌ **DON'T**: Skip running tests  
✅ **DO**: Run full test suite after any cleanup

---

## Continuous Integration

These scripts are designed to work in CI/CD pipelines:

```yaml
# Example GitHub Actions workflow
test:
  runs-on: ubuntu-latest
  steps:
    - uses: actions/checkout@v2
    - name: Start Docker container
      run: docker-compose up -d
    - name: Run tests
      run: ./scripts/docker-test.sh --verbose
    - name: Check coverage
      run: ./scripts/docker-inventory.sh coverage --missing
```

---

## Troubleshooting

### Container not running

```bash
# Check container status
docker ps -a | grep symbiota

# Start container
docker start symbiota-web

# Or use docker-compose
docker-compose up -d
```

### Permission denied

```bash
# Make scripts executable
chmod +x scripts/docker-test.sh
chmod +x scripts/docker-inventory.sh
```

### Different container name

```bash
# Set environment variable
export DOCKER_CONTAINER=my-container-name

# Or edit scripts directly
# Change CONTAINER_NAME variable at top of script
```

---

## Related Documentation

- [Hybrid Search Plan](../docs/HYBRID_SEARCH_PLAN.md) - Implementation plan for hybrid search backend
- [Code Cleanup Plan](../docs/CODE_CLEANUP_PLAN.md) - Systematic code cleanup documentation
- [Kahlan Documentation](https://kahlan.github.io/docs/) - Test framework documentation

---

## Contributing

When adding new scripts:

1. Make them executable: `chmod +x scripts/new-script.sh`
2. Add help text: `./scripts/new-script.sh --help`
3. Document in this README
4. Test in Docker environment
5. Add error handling and validation

