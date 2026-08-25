#!/usr/bin/env bash

# Wrapper script to run tests inside Docker container
# Passes all arguments to the run-tests.sh script inside the container

set -e

# Check if Docker is running
if ! docker info &> /dev/null; then
    echo "Error: Docker is not running"
    echo "Please start Docker Desktop and try again"
    exit 1
fi

# Check if containers are running
if ! docker-compose ps | grep -q "wordpress.*Up"; then
    echo "Error: WordPress container is not running"
    echo "Please run 'docker-compose up -d' first or use './scripts/bootstrap.sh'"
    exit 1
fi

# Run tests inside Docker container, passing all arguments
docker-compose exec wordpress bash -c "cd wp-content/plugins/awesome-events && /scripts/run-tests.sh $*"
