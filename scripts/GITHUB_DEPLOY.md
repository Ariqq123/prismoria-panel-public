# GitHub Deployment Flow (Custom Panel)

This repo is prepared to deploy a full custom panel from GitHub on a fresh VPS.

## 1) Push this panel to GitHub

Run these on your current panel machine (`/var/www/pterodactyl`):

```bash
git init
git checkout -b main
git add .
git commit -m "Initial custom panel snapshot"
git remote add origin https://github.com/<your-user>/<your-repo>.git
git push -u origin main
```

Notes:
- `.env` is ignored by default; secrets are not pushed.
- Large local artifacts (`panel.tar.gz`, `nooktheme.tar.gz`, `release.zip`) are ignored.

## 2) Deploy on a fresh machine (git clone + installer)

```bash
sudo apt-get update -y && sudo apt-get install -y git
sudo git clone https://github.com/<your-user>/<your-repo>.git /var/www/pterodactyl
cd /var/www/pterodactyl
sudo bash scripts/auto-setup-from-repo.sh \
  --panel-dir /var/www/pterodactyl \
  --domain panel.example.com \
  --letsencrypt-email admin@example.com
```

## 3) Deploy directly from GitHub repo URL (script clones for you)

```bash
sudo mkdir -p /opt/ptero-bootstrap && cd /opt/ptero-bootstrap
sudo git clone https://github.com/<your-user>/<your-repo>.git .
sudo bash scripts/auto-setup-from-repo.sh \
  --repo https://github.com/<your-user>/<your-repo>.git \
  --branch main \
  --domain panel.example.com \
  --letsencrypt-email admin@example.com
```

## 4) Optional flags you will likely use

```bash
--db-name panel
--db-user pterodactyl
--db-pass '<strong-password>'
--db-root-user root
--db-root-pass '<mariadb-root-password>'
--db-dump /root/panel-db.sql.gz
--redis-version auto
--php-version 8.3
--build-assets
```

## 5) Post-install checks

```bash
sudo systemctl status nginx php8.3-fpm redis-server pteroq pterodactyl-external-ws-proxy --no-pager
sudo -u www-data php /var/www/pterodactyl/artisan p:environment:database
```

If `pteroq` fails, first check:

```bash
sudo chown -R www-data:www-data /var/www/pterodactyl/storage /var/www/pterodactyl/bootstrap/cache
sudo systemctl restart pteroq
```
