#!/bin/bash
# ============================================
# WarehouseOps v5 Deployment Script
# Run this on your Ubuntu server
# ============================================

set -e

APP_DIR="/opt/warehouseops"
COMPOSE_FILE="docker-compose.yml"

echo "=========================================="
echo "  WarehouseOps v5 Deployment"
echo "=========================================="

# Check Docker
if ! command -v docker &> /dev/null; then
    echo "ERROR: Docker is not installed"
    exit 1
fi

if ! docker compose version &> /dev/null; then
    echo "ERROR: Docker Compose plugin is not installed"
    exit 1
fi

# Create app directory
echo "Creating application directory..."
sudo mkdir -p "$APP_DIR"
sudo chown -R $USER:$USER "$APP_DIR"

# Copy files if running from source
if [ -f "$COMPOSE_FILE" ]; then
    echo "Copying application files..."
    cp -r . "$APP_DIR/"
fi

cd "$APP_DIR"

# Create .env if not exists
if [ ! -f .env ]; then
    echo "Creating .env file..."
    cp .env.example .env

    # Generate app key placeholder
    APP_KEY=$(openssl rand -base64 32)
    sed -i "s/APP_KEY=/APP_KEY=base64:$APP_KEY/" .env
fi

# Build and start containers
echo "Building Docker images..."
docker compose build --no-cache

echo "Starting services..."
docker compose up -d

# Wait for services to be healthy
echo "Waiting for services to start..."
sleep 10

# Run migrations
echo "Running database migrations..."
docker compose exec -T app php artisan migrate --force

# Create storage link
echo "Creating storage link..."
docker compose exec -T app php artisan storage:link 2>/dev/null || true

# Clear and cache config
echo "Optimizing application..."
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache

# Show status
echo ""
echo "=========================================="
echo "  Deployment Complete!"
echo "=========================================="
echo ""
docker compose ps
echo ""
echo "Access the application at: http://$(hostname -I | awk '{print $1}'):8080"
echo ""
