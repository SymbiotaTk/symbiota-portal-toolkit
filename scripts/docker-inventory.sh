#!/bin/bash
#
# Docker Inventory Runner for Symbiota Portal Toolkit
#
# This script runs inventory analysis inside the Docker container
# to identify unused code, check test coverage, and optimize the codebase.
#
# Usage:
#   ./scripts/docker-inventory.sh analyze              # Analyze all models
#   ./scripts/docker-inventory.sh analyze --model=images  # Analyze specific model
#   ./scripts/docker-inventory.sh unused               # Find unused code
#   ./scripts/docker-inventory.sh coverage             # Check test coverage
#   ./scripts/docker-inventory.sh sql-templates        # List SQL templates
#   ./scripts/docker-inventory.sh cleanup              # Full cleanup workflow
#

set -e  # Exit on error

# Configuration
CONTAINER_NAME="${DOCKER_CONTAINER:-symbiota-web}"
TK_PATH="/var/www/html/portal/tk"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Helper functions
print_header() {
    echo -e "${BLUE}╔════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║${NC}  Symbiota Portal Toolkit - Code Inventory & Cleanup         ${BLUE}║${NC}"
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

print_step() {
    echo -e "${CYAN}▶${NC} $1"
}

# Check if Docker container is running
check_container() {
    if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
        print_error "Docker container '${CONTAINER_NAME}' is not running"
        echo ""
        echo "Start the container first:"
        echo "  docker start ${CONTAINER_NAME}"
        exit 1
    fi
}

# Run inventory command in Docker
run_inventory() {
    local cmd="$1"
    docker exec ${CONTAINER_NAME} bash -c "cd ${TK_PATH} && php index.php inventory ${cmd}"
}

# Full cleanup workflow
cleanup_workflow() {
    print_header
    print_info "Starting full cleanup workflow..."
    echo ""
    
    # Step 1: Analyze codebase
    print_step "Step 1: Analyzing codebase for unused code..."
    run_inventory "analyze --include-unused"
    echo ""
    
    # Step 2: Find unused code
    print_step "Step 2: Identifying unused code..."
    run_inventory "unused"
    echo ""
    
    # Step 3: Check test coverage
    print_step "Step 3: Checking test coverage..."
    run_inventory "coverage --missing-only"
    echo ""
    
    # Step 4: List unused SQL templates
    print_step "Step 4: Finding unused SQL templates..."
    run_inventory "sql-templates --unused-only"
    echo ""
    
    print_success "Cleanup analysis complete!"
    echo ""
    print_info "Next steps:"
    echo "  1. Review the unused code identified above"
    echo "  2. Create removal plan: ./scripts/docker-inventory.sh create-plan"
    echo "  3. Run tests: ./scripts/docker-test.sh"
    echo "  4. Delete .REMOVE files after verification"
}

# Create removal plan
create_removal_plan() {
    print_header
    print_info "Creating removal plan..."
    echo ""
    
    local model="${1:-}"
    local cmd="unused --create-plan"
    
    if [ -n "$model" ]; then
        cmd="${cmd} --model=${model}"
        print_info "Model: ${model}"
    else
        print_info "Model: all"
    fi
    
    echo ""
    run_inventory "$cmd"
    echo ""
    
    print_success "Removal plan created!"
    print_info "Review the plan above and execute manually if safe"
}

# Show help
show_help() {
    echo "Usage: $0 COMMAND [OPTIONS]"
    echo ""
    echo "Commands:"
    echo "  analyze              Analyze all models for active functionality"
    echo "  analyze --model=NAME Analyze specific model only"
    echo "  unused               Find potentially unused code"
    echo "  unused --model=NAME  Find unused code in specific model"
    echo "  coverage             Show test coverage for all models"
    echo "  coverage --missing   Show only untested functionality"
    echo "  sql-templates        List all SQL templates and usage"
    echo "  sql-templates --unused  Show only unused SQL templates"
    echo "  cleanup              Run full cleanup workflow (all steps)"
    echo "  create-plan [MODEL]  Create removal plan for unused code"
    echo ""
    echo "Examples:"
    echo "  $0 analyze                    # Analyze all models"
    echo "  $0 analyze --model=images     # Analyze Images model only"
    echo "  $0 unused                     # Find all unused code"
    echo "  $0 coverage --missing         # Show untested code"
    echo "  $0 cleanup                    # Full cleanup workflow"
    echo "  $0 create-plan images         # Create removal plan for Images model"
    echo ""
    echo "Environment Variables:"
    echo "  DOCKER_CONTAINER    Docker container name (default: symbiota-web)"
}

# Main execution
if [ $# -eq 0 ]; then
    show_help
    exit 0
fi

check_container

case "$1" in
    analyze)
        print_header
        shift
        run_inventory "analyze $*"
        ;;
    unused)
        print_header
        shift
        run_inventory "unused $*"
        ;;
    coverage)
        print_header
        shift
        run_inventory "coverage $*"
        ;;
    sql-templates)
        print_header
        shift
        run_inventory "sql-templates $*"
        ;;
    cleanup)
        cleanup_workflow
        ;;
    create-plan)
        shift
        create_removal_plan "$1"
        ;;
    --help|-h|help)
        show_help
        ;;
    *)
        print_error "Unknown command: $1"
        echo ""
        show_help
        exit 1
        ;;
esac

