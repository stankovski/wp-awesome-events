#!/usr/bin/env bash

# Run PHPUnit tests for Awesome Events plugin

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "========================================="
echo "Awesome Events - Test Runner"
echo "========================================="

# Define plugin directory - use current dir if already in plugin, otherwise use full path
if [ -f "awesome-events.php" ]; then
    # Already in plugin directory
    PLUGIN_DIR="."
else
    # Need to navigate to plugin directory
    PLUGIN_DIR="wp-content/plugins/awesome-events"
fi

# Check if vendor directory exists
if [ ! -d "$PLUGIN_DIR/vendor" ]; then
    echo -e "${RED}Error: vendor directory not found${NC}"
    echo "Please run 'composer install' in the plugin directory first"
    exit 1
fi

# Check if PHPUnit is installed
if [ ! -f "$PLUGIN_DIR/vendor/bin/phpunit" ]; then
    echo -e "${RED}Error: PHPUnit not found${NC}"
    echo "Please run 'composer install' in $PLUGIN_DIR first"
    exit 1
fi

# Check if WordPress test environment is available
WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_TESTS_AVAILABLE=0
if [ -f "$WP_TESTS_DIR/includes/functions.php" ]; then
    WP_TESTS_AVAILABLE=1
fi

# Parse command line arguments
TEST_SUITE=""
COVERAGE=0

while [[ $# -gt 0 ]]; do
    case $1 in
        --unit)
            TEST_SUITE="Unit Tests"
            shift
            ;;
        --integration)
            TEST_SUITE="Integration Tests"
            if [ $WP_TESTS_AVAILABLE -eq 0 ]; then
                echo -e "${RED}Error: WordPress test environment not found${NC}"
                echo "Please run 'scripts/install-wp-tests.sh' first (uses Docker defaults)"
                echo "Or specify custom parameters: scripts/install-wp-tests.sh <db-name> <db-user> <db-pass>"
                echo "WordPress tests are expected at: $WP_TESTS_DIR"
                exit 1
            fi
            shift
            ;;
        --coverage)
            COVERAGE=1
            shift
            ;;
        --help|-h)
            echo ""
            echo "Usage: $0 [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --unit           Run only unit tests"
            echo "  --integration    Run only integration tests"
            echo "  --coverage       Generate code coverage report"
            echo "  --help, -h       Show this help message"
            echo ""
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            echo "Run '$0 --help' for usage information"
            exit 1
            ;;
    esac
done

# Change to plugin directory
cd "$PLUGIN_DIR" || exit 1

# Build PHPUnit command
PHPUNIT_CMD="vendor/bin/phpunit"

if [ -n "$TEST_SUITE" ]; then
    # User specified a test suite
    PHPUNIT_CMD="$PHPUNIT_CMD --testsuite \"$TEST_SUITE\""
    echo -e "${YELLOW}Running: $TEST_SUITE${NC}"
    
    # Set TEST_SUITE environment variable for bootstrap
    if [ "$TEST_SUITE" = "Integration Tests" ]; then
        export TEST_SUITE="integration"
    else
        export TEST_SUITE="unit"
    fi
else
    # No test suite specified - run all or only unit tests based on WP environment availability
    if [ $WP_TESTS_AVAILABLE -eq 0 ]; then
        echo -e "${YELLOW}Note: WordPress test environment not found. Running unit tests only.${NC}"
        echo -e "${YELLOW}To run integration tests, first run: scripts/install-wp-tests.sh${NC}"
        PHPUNIT_CMD="$PHPUNIT_CMD --testsuite \"Unit Tests\""
        export TEST_SUITE="unit"
        echo ""
        echo -e "${YELLOW}Running: Unit Tests${NC}"
    else
        echo -e "${YELLOW}Running: All Tests${NC}"
        # When running all tests with WP available, use integration bootstrap
        # (it loads WordPress which is needed for integration tests, but unit tests can still run)
        export TEST_SUITE="integration"
    fi
fi

if [ $COVERAGE -eq 1 ]; then
    echo -e "${YELLOW}Generating code coverage report...${NC}"
    PHPUNIT_CMD="$PHPUNIT_CMD --coverage-html coverage/"
fi

echo ""

# Run PHPUnit
eval $PHPUNIT_CMD
EXIT_CODE=$?

echo ""
if [ $EXIT_CODE -eq 0 ]; then
    echo -e "${GREEN}✓ All tests passed!${NC}"
    if [ $COVERAGE -eq 1 ]; then
        echo -e "${GREEN}Coverage report generated in coverage/ directory${NC}"
    fi
else
    echo -e "${RED}✗ Tests failed with exit code $EXIT_CODE${NC}"
fi

exit $EXIT_CODE
