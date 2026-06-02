---
description: Server access and management commands for 192.168.0.15
---

# Server Management Workflow

**Host:** `192.168.0.15`
**User:** `it-admin`
**Sudo Password:** `!admin00`
**App Container:** `warehouseops-app`
**Web Container:** `warehouseops-nginx`
**Redis Container:** `warehouseops-redis`

## SSH Access

```bash
sshpass -p '!admin00' ssh -o StrictHostKeyChecking=no it-admin@192.168.0.15
```

## Container Status

```bash
sshpass -p '!admin00' ssh -o StrictHostKeyChecking=no it-admin@192.168.0.15 "sudo docker ps"
```

## View App Logs

```bash
sshpass -p '!admin00' ssh -o StrictHostKeyChecking=no it-admin@192.168.0.15 "sudo docker logs --tail 50 warehouseops-app"
```

## View Nginx Logs

```bash
sshpass -p '!admin00' ssh -o StrictHostKeyChecking=no it-admin@192.168.0.15 "sudo docker logs --tail 50 warehouseops-nginx"
```

## Restart Services (Fix 502)

```bash
sshpass -p '!admin00' ssh -o StrictHostKeyChecking=no it-admin@192.168.0.15 "sudo docker start warehouseops-nginx && sudo docker restart warehouseops-app warehouseops-nginx warehouseops-redis"
```

## Clear Laravel Cache

```bash
sshpass -p '!admin00' ssh -o StrictHostKeyChecking=no it-admin@192.168.0.15 "sudo docker exec warehouseops-app php artisan optimize:clear && sudo docker exec warehouseops-app php artisan optimize"
```

## Run Migrations

```bash
sshpass -p '!admin00' ssh -o StrictHostKeyChecking=no it-admin@192.168.0.15 "sudo docker exec warehouseops-app php artisan migrate --force"
```

## Check PHP-FPM Status

```bash
sshpass -p '!admin00' ssh -o StrictHostKeyChecking=no it-admin@192.168.0.15 "sudo docker exec warehouseops-app ps aux | grep php-fpm"
```

## Copy Files to Server

```bash
# Single file
sshpass -p '!admin00' scp -o StrictHostKeyChecking=no local-file it-admin@192.168.0.15:/tmp/

# Into container
sshpass -p '!admin00' ssh -o StrictHostKeyChecking=no it-admin@192.168.0.15 "sudo docker cp /tmp/local-file warehouseops-app:/var/www/html/path/"
```

## Deploy Build Assets

```bash
# Build locally first
cd /home/it-admin/Downloads/automation/ai-system/projects/warehouseops-v5-main && npm run build

# Tar and deploy
tar czf /tmp/build.tar.gz public/build/
sshpass -p '!admin00' scp -o StrictHostKeyChecking=no /tmp/build.tar.gz it-admin@192.168.0.15:/tmp/
sshpass -p '!admin00' ssh -o StrictHostKeyChecking=no it-admin@192.168.0.15 "sudo docker cp /tmp/build.tar.gz warehouseops-app:/tmp/ && sudo docker exec warehouseops-app sh -c 'cd /var/www/html && tar xzf /tmp/build.tar.gz' && sudo docker restart warehouseops-app"
```

## Full Health Check

```bash
sshpass -p '!admin00' ssh -o StrictHostKeyChecking=no it-admin@192.168.0.15 "
sudo docker ps
for c in warehouseops-app warehouseops-nginx warehouseops-redis; do
  status=\$(sudo docker inspect --format='{{.State.Status}}' \$c 2>/dev/null)
  echo \"\$c: \$status\"
done
curl -s -o /dev/null -w '%{http_code}' http://localhost:8102/login
"
```
