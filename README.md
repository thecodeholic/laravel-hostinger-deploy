# Laravel Hostinger Deploy

Deploy your Laravel application to Hostinger shared hosting with automated GitHub Actions support.

## Installation

```bash
composer require thecodeholic/laravel-hostinger-deploy --dev
```

## Quick Start (All-in-One Command)

The easiest way to deploy and set up automated deployment:

```bash
php artisan hostinger:deploy-and-setup-automated
```

### Required Environment Variables

Add these to your `.env` file:

```env
HOSTINGER_SSH_HOST=your-server-ip
HOSTINGER_SSH_USERNAME=your-username
HOSTINGER_SSH_PORT=22
HOSTINGER_SITE_DIR=your-website-folder
GITHUB_API_TOKEN=your-github-token
```

**What this command does:**
1. Deploys your Laravel application to Hostinger
2. Sets up SSH keys on the server
3. Creates GitHub Actions workflow file
4. Configures GitHub secrets and variables via API

**Command Options:**
- `--fresh` - Delete existing files and clone fresh repository
- `--site-dir=` - Override site directory from config
- `--token=` - GitHub API token (overrides GITHUB_API_TOKEN from .env)
- `--branch=` - Branch to deploy (default: auto-detect)
- `--php-version=` - PHP version for workflow (default: 8.3)

## Individual Commands

### 1. Manual Deployment Only

```bash
php artisan hostinger:deploy-shared
```

**What it does:** Deploys your Laravel application to Hostinger server (composer install, migrations, storage link, etc.)

**Required Environment Variables:**
- `HOSTINGER_SSH_HOST`
- `HOSTINGER_SSH_USERNAME`
- `HOSTINGER_SSH_PORT`
- `HOSTINGER_SITE_DIR`

**Command Options:**
- `--fresh` - Delete existing files and clone fresh repository
- `--site-dir=` - Override site directory from config

---

### 2. Setup Automated Deployment (Manual)

```bash
php artisan hostinger:auto-deploy
```

**What it does:** Generates SSH keys on server and displays GitHub secrets/variables for manual setup

**Required Environment Variables:**
- `HOSTINGER_SSH_HOST`
- `HOSTINGER_SSH_USERNAME`
- `HOSTINGER_SSH_PORT`
- `HOSTINGER_SITE_DIR`

**No options** - Run this command, then manually add the displayed secrets to GitHub

---

### 3. Create GitHub Actions Workflow File

```bash
php artisan hostinger:publish-workflow
```

**What it does:** Creates `.github/workflows/hostinger-deploy.yml` file locally

**Required Environment Variables:** None (must be in a Git repository)

**Command Options:**
- `--branch=` - Branch to trigger deployment (default: auto-detect)
- `--php-version=` - PHP version for workflow (default: 8.3)

---

### 4. Setup Automated Deployment (Via GitHub API)

```bash
php artisan hostinger:setup-automated-deploy
```

**What it does:** Creates GitHub Actions workflow and secrets automatically via GitHub API

**Required Environment Variables:**
- `HOSTINGER_SSH_HOST`
- `HOSTINGER_SSH_USERNAME`
- `HOSTINGER_SSH_PORT`
- `HOSTINGER_SITE_DIR`
- `GITHUB_API_TOKEN`

**Command Options:**
- `--token=` - GitHub API token (overrides GITHUB_API_TOKEN from .env)
- `--branch=` - Branch to deploy (default: auto-detect)
- `--php-version=` - PHP version for workflow (default: 8.3)

## Environment Variables Summary

| Variable | Required For | Description |
|----------|--------------|-------------|
| `HOSTINGER_SSH_HOST` | All commands | Hostinger server IP address |
| `HOSTINGER_SSH_USERNAME` | All commands | Hostinger SSH username |
| `HOSTINGER_SSH_PORT` | All commands | SSH port (default: 22) |
| `HOSTINGER_SITE_DIR` | All commands | Website folder name |
| `GITHUB_API_TOKEN` | Automated setup | GitHub personal access token (repo, workflow scopes) |

## Requirements

- PHP ^8.2
- Laravel ^11.0|^12.0
- SSH access to Hostinger server
- Git repository (GitHub recommended)

## License

MIT
