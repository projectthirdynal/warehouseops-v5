---
title: "fix(deploy): chown to runner user breaks container permissions"
labels: bug, devops, P1
---

## What to build

`.github/workflows/deploy.yml` runs `sudo chown -R $(whoami):$(whoami) /opt/warehouseops`, which changes ownership of `storage/`, `bootstrap/cache/`, and other directories to the runner user. PHP-FPM in the container runs as `www-data` and loses write access to these directories.

## Acceptance criteria

- [ ] Remove or fix the `chown` step to preserve `www-data` ownership on `storage/` and `bootstrap/cache/`.
- [ ] Use `sudo rsync` with `--chmod` flags instead of blanket `chown`.
- [ ] Verify the app container can still write to `storage/logs/` and `storage/framework/` after deploy.

## Blocked by

None — can start immediately.
