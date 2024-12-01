# Default variables
COMPOSE = docker-compose
SERVICE = php
CONTAINER = php-extension-dev

# Default target: help
.PHONY: help
help:
	@echo "Available commands:"
	@echo "  make build      - Build the Docker image"
	@echo "  make up         - Start the container"
	@echo "  make down       - Stop and remove the container"
	@echo "  make shell      - Access the container's shell"
	@echo "  make clean      - Remove containers and associated volumes"

# Build the Docker image
.PHONY: docker-build
build:
	$(COMPOSE) build

# Start the container
.PHONY: up
up:
	$(COMPOSE) up -d

# Stop and remove the container
.PHONY: down
down:
	$(COMPOSE) down

# Access the shell inside the container
.PHONY: shell
shell:
	$(COMPOSE) exec $(SERVICE) bash

# Clean up containers and volumes
.PHONY: clean
clean:
	$(COMPOSE) down -v
