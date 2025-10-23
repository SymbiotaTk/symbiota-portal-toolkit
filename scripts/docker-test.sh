#!/bin/bash
#
# Docker Test Runner for Symbiota Portal Toolkit
#
# This script runs Kahlan tests inside the Docker container
# to ensure consistent test environment across development machines.
#
# Usage:
#   ./scripts/docker-test.sh                    # Run all tests
#   ./scripts/docker-test.sh unit               # Run unit tests only
#   ./scripts/docker-test.sh integration        # Run integration tests only
#   ./scripts/docker-test.sh spec/path/to/test  # Run specific test file
#   ./scripts/docker-test.sh --verbose          # Run with verbose output
#   ./scripts/docker-test.sh --coverage         # Run with coverage report
#

set -e  # Exit on error

# Configuration
CONTAINER_NAME="${DOCKER_CONTAINER:-symbiota-web}"
TK_PATH="/var/www/html/portal/tk"
KAHLAN_BIN="vendor/bin/kahlan"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Helper functions
print_header() {
    echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║${NC}  Symbiota Portal Toolkit - Docker Test Runner              ${BLUE}║${NC}"
    echo -e "${BLUE}╚════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

print_success() {
    echo -e "${GREEN}✓${NC} $1"
}

print_error() {
    echo -e "${RED}✗${NC} $1"
}

print_info() {
    echo -e "${YELLOW}ℹ${NC} $1"
}

# Check if Docker container is running
check_container() {
    if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
        print_error "Docker container '${CONTAINER_NAME}' is not running"
        echo ""
        echo "Start the container first:"
        echo "  docker start ${CONTAINER_NAME}"
        echo ""
        echo "Or set DOCKER_CONTAINER environment variable:"
        echo "  export DOCKER_CONTAINER=your-container-name"
        exit 1
    fi
    print_success "Docker container '${CONTAINER_NAME}' is running"
}

# Parse command line arguments
KAHLAN_ARGS=""
TEST_SUITE=""
VERBOSE=false
COVERAGE=false

while [[ $# -gt 0 ]]; do
    case $1 in
        unit)
            TEST_SUITE="unit"
            KAHLAN_ARGS="--spec=spec/unit/"
            shift
            ;;
        integration)
            TEST_SUITE="integration"
            KAHLAN_ARGS="--spec=spec/integration/"
            shift
            ;;
        security)
            TEST_SUITE="security"
            KAHLAN_ARGS="--spec=spec/security/"
            shift
            ;;
        --verbose|-v)
            VERBOSE=true
            KAHLAN_ARGS="${KAHLAN_ARGS} --reporter=verbose"
            shift
            ;;
        --coverage)
            COVERAGE=true
            KAHLAN_ARGS="${KAHLAN_ARGS} --coverage=4"
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [OPTIONS] [TEST_SUITE]"
            echo ""
            echo "Options:"
            echo "  unit              Run unit tests only"
            echo "  integration       Run integration tests only"
            echo "  security          Run security tests only"
            echo "  --verbose, -v     Run with verbose output"
            echo "  --coverage        Run with coverage report"
            echo "  --help, -h        Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0                              # Run all tests"
            echo "  $0 unit                         # Run unit tests"
            echo "  $0 --verbose                    # Run all tests with verbose output"
            echo "  $0 spec/unit/Models/ImagesModelSpec.php  # Run specific test"
            exit 0
            ;;
        spec/*)
            # Specific test file
            KAHLAN_ARGS="--spec=$1"
            shift
            ;;
        *)
            print_error "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Main execution
print_header

# Check Docker container
check_container
echo ""

# Show test configuration
print_info "Test Configuration:"
if [ -n "$TEST_SUITE" ]; then
    echo "  Suite:     ${TEST_SUITE}"
else
    echo "  Suite:     all"
fi
echo "  Verbose:   ${VERBOSE}"
echo "  Coverage:  ${COVERAGE}"
echo "  Container: ${CONTAINER_NAME}"
echo "  Path:      ${TK_PATH}"
echo ""

# Build Docker command
DOCKER_CMD="docker exec ${CONTAINER_NAME} bash -c \"cd ${TK_PATH} && ${KAHLAN_BIN} ${KAHLAN_ARGS}\""

print_info "Running tests..."
echo ""

# Execute tests
if eval $DOCKER_CMD; then
    echo ""
    print_success "All tests passed!"
    exit 0
else
    echo ""
    print_error "Tests failed!"
    exit 1
fi

