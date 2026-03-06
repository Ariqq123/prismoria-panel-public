<!-- Header -->
<br/><p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://github.com/BlueprintFramework/framework/assets/103201875/c0072c61-0135-4931-b5fa-ce4ee7d79f4a">
    <source media="(prefers-color-scheme: light)" srcset="https://github.com/BlueprintFramework/framework/assets/103201875/a652a6e7-b53f-4dcd-ae4e-2051f5c9c7b9">
    <img alt="Blueprint" src="https://github.com/BlueprintFramework/framework/assets/103201875/c0072c61-0135-4931-b5fa-ce4ee7d79f4a" height="30">
  </picture>
  <br/>
  Open-source modding framework for the Pterodactyl panel.
  <br/><br/>
  <a href="https://blueprint.zip">Website</a> <b>·</b>
  <a href="https://discord.com/servers/blueprint-1063548024825057451">Community</a> <b>·</b>
  <a href="https://blueprint.zip/docs">Documentation</a>
</p>

<!-- Prismoria Setup -->
<br/><h2 align="center">🚀 Prismoria Panel Setup</h2>

This repository contains the custom Prismoria panel build (Pterodactyl + Blueprint extensions + custom modules).

### What Is Included

- Prismoria UI/UX customization (Nook-style layout, dock, dark/light mode, themed components).
- External panel integration (external servers, API connections, and external websocket proxy support).
- Custom modules: server backgrounds, player manager, subdomain manager, version changer, status, votifier tester, mclogs integration, and more.
- Auto-backup system with provider support (Google Drive, S3, Dropbox) for local and external servers.
- Installer and migration scripts for fresh VPS deployment.

### Install On A New VPS

```bash
sudo apt-get update -y && sudo apt-get install -y git
sudo git clone https://github.com/Ariqq123/prismoria-panel.git /var/www/pterodactyl
cd /var/www/pterodactyl
sudo bash scripts/auto-setup-from-repo.sh \
  --panel-dir /var/www/pterodactyl \
  --domain panel.example.com \
  --letsencrypt-email admin@example.com
```

Public mirror:

```bash
sudo apt-get update -y && sudo apt-get install -y git
sudo git clone https://github.com/Ariqq123/prismoria-panel-public.git /var/www/pterodactyl
cd /var/www/pterodactyl
sudo bash scripts/auto-setup-from-repo.sh \
  --panel-dir /var/www/pterodactyl \
  --domain panel.example.com \
  --letsencrypt-email admin@example.com
```

Optional install flags:

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

### Post-Install Health Checks

```bash
sudo systemctl status redis-server --no-pager
sudo systemctl status pteroq --no-pager
sudo systemctl status nginx --no-pager
sudo php artisan p:environment:database
sudo php artisan queue:failed
```

If queue/redis fails after migration or restore, verify ownership:

```bash
sudo chown -R www-data:www-data /var/www/pterodactyl/storage /var/www/pterodactyl/bootstrap/cache
```

### Commit And Push Changes (Private + Public)

```bash
cd /var/www/pterodactyl
git checkout main
git pull --ff-only origin main
git add .
git commit -m "Describe your panel change"
git push origin main
git push public main
```

For full deployment details, see [`scripts/GITHUB_DEPLOY.md`](scripts/GITHUB_DEPLOY.md).

### Module Guides

- Auto Backup (Google Drive, S3, Dropbox, local + external servers): [`docs/AUTO_BACKUPS.md`](docs/AUTO_BACKUPS.md)
- DriveBackupV2 local bridge setup: [`docs/DRIVEBACKUPV2_SETUP.md`](docs/DRIVEBACKUPV2_SETUP.md)



<!-- Introduction -->
<br/><h2 align="center">🧩 Introduction</h2>

**Blueprint** is an open-source extension framework/manager for Pterodactyl. Developers can create versatile, easy-to-install extensions that system administrators can install within minutes *(usually even seconds!)* without having to custom-code compatibility across multiple panel modifications.

We aim to introduce new developers to Blueprint with easy to understand guides, documentation, developer commands, community support and more.

[Learn more about **Blueprint**](https://blueprint.zip) or [find your **next extension**](https://blueprint.zip/browse).



<!-- Showcase -->
<br/><h2 align="center">📷 Showcase</h2>

![screenshots](https://github.com/BlueprintFramework/framework/assets/103201875/cb66943e-a60e-44e5-afd4-90475b106244)



<br/><h2 align="center">💖 Donate</h2>

Blueprint is free and open-source software. We play a vital role in the Pterodactyl modding community and empower developers with tools to bring their ideas to life. To keep everything up and running, we rely heavily on [donations](https://hcb.hackclub.com/blueprint/donations). We're also nonprofit!

[**Donate to our nonprofit organization**](https://hcb.hackclub.com/donations/start/blueprint) or [view our open finances](https://hcb.hackclub.com/blueprint).


<!-- Contributors -->
<br/><h2 align="center">👥 Contributors</h2>

Contributors help shape the future of the Blueprint modding framework. To start contributing you have to [fork this repository](https://github.com/BlueprintFramework/framework/fork) and [open a pull request](https://github.com/BlueprintFramework/framework/compare).

<a href="https://github.com/BlueprintFramework/framework/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=BlueprintFramework/framework" />
</a>



<!-- Stargazers -->
<br/><h2 align="center">🌟 Stargazers</h2>

<a href="https://github.com/BlueprintFramework/framework/stargazers/">
  <picture>
    <source media="(prefers-color-scheme: light)" srcset="http://reporoster.com/stars/BlueprintFramework/framework">
    <img alt="stargazer-widget" src="http://reporoster.com/stars/dark/BlueprintFramework/framework">
  </picture>
</a>



<!-- Related Links -->
<br/><h2 align="center">🔗 Related Links</h2>

[**Pterodactyl**](https://pterodactyl.io/) is a free, open-source game server management panel built with PHP, React, and Go.\
[**BlueprintFramework/docker**](https://github.com/BlueprintFramework/docker) is the image for running Blueprint and Pterodactyl with Docker.\
[**BlueprintFramework/templates**](https://github.com/BlueprintFramework/templates) is a repository with initialization templates for extension development.\
[**BlueprintFramework/web**](https://github.com/BlueprintFramework/web) is our open-source documentation and landing website.


<br/><br/>
<p align="center">
  © 2023-2026 Emma (prpl.wtf)
  <br/><br/><img src="https://github.com/user-attachments/assets/e6ff62c3-6d99-4e43-850d-62150706e5dd"/>
</p>


