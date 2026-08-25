#!/usr/bin/env bash

# Bootstrap script for Awesome Events Development Environment
# Sets up Docker containers, WordPress test environment, and dependencies

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}=========================================${NC}"
echo -e "${BLUE}Awesome Events - Bootstrap${NC}"
echo -e "${BLUE}=========================================${NC}"
echo ""

# Check if Docker is installed
if ! command -v docker &> /dev/null; then
    echo -e "${RED}Error: Docker is not installed${NC}"
    echo "Please install Docker Desktop from https://www.docker.com/products/docker-desktop"
    exit 1
fi

# Check if Docker is running
if ! docker info &> /dev/null; then
    echo -e "${RED}Error: Docker is not running${NC}"
    echo "Please start Docker Desktop and try again"
    exit 1
fi

echo -e "${GREEN}✓ Docker is running${NC}"

# Check if docker-compose is available
if ! command -v docker-compose &> /dev/null; then
    echo -e "${RED}Error: docker-compose is not installed${NC}"
    exit 1
fi

echo -e "${GREEN}✓ docker-compose is available${NC}"
echo ""

# Start Docker containers
echo -e "${YELLOW}Starting Docker containers...${NC}"
docker-compose up -d
echo -e "${GREEN}✓ Docker containers started${NC}"
echo ""

# Wait for MySQL to be ready
echo -e "${YELLOW}Waiting for MySQL to be ready...${NC}"
sleep 5

MAX_ATTEMPTS=30
ATTEMPT=0
while ! docker-compose exec -T db mysql -uroot -proot -e "SELECT 1" &> /dev/null; do
    ATTEMPT=$((ATTEMPT + 1))
    if [ $ATTEMPT -ge $MAX_ATTEMPTS ]; then
        echo -e "${RED}Error: MySQL failed to start${NC}"
        exit 1
    fi
    echo -e "${YELLOW}  Waiting for MySQL... (attempt $ATTEMPT/$MAX_ATTEMPTS)${NC}"
    sleep 2
done

echo -e "${GREEN}✓ MySQL is ready${NC}"
echo ""

# Install WordPress test environment
echo -e "${YELLOW}Installing WordPress test environment...${NC}"
docker-compose exec -T wordpress bash /scripts/install-wp-tests.sh <<< "y"
echo -e "${GREEN}✓ WordPress test environment installed${NC}"
echo ""

# Summary
echo -e "${BLUE}=========================================${NC}"
echo -e "${GREEN}Bootstrap Complete!${NC}"
echo -e "${BLUE}=========================================${NC}"
echo ""
echo "Your development environment is ready:"
echo ""
echo -e "  ${BLUE}WordPress:${NC}     http://localhost:8000"
echo -e "  ${BLUE}phpMyAdmin:${NC}    http://localhost:8080"
echo ""
echo "Useful commands:"
echo -e "  ${YELLOW}Stop Docker:${NC}     docker-compose down"
echo ""
