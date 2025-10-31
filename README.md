# Laravel Hostinger Deploy

Deploy your Laravel application to Hostinger shared hosting with automated GitHub Actions support.

## Installation

Install the package via Composer:

```bash
composer require thecodeholic/laravel-hostinger-deploy:^0.2 --dev
```

Or install the latest version:

```bash
composer require thecodeholic/laravel-hostinger-deploy --dev
```

> **Note:** This package should be installed as a development dependency (`--dev`) since it's only needed during deployment, not in production.

## Quick Start (All-in-One Command)

The easiest way to deploy and set up automated deployment:

```bash
php artisan hostinger:deploy-and-setup-cicd
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
3. Automatically adds deploy key to GitHub repository via API
4. Publishes GitHub Actions workflow file locally (`.github/workflows/hostinger-deploy.yml`)
5. Configures GitHub secrets and variables via API

**Command Options:**
- `--fresh` - Delete existing files and clone fresh repository
- `--site-dir=` - Override site directory from config
- `--token=` - GitHub Personal Access Token (overrides GITHUB_API_TOKEN from .env)
- `--branch=` - Branch to deploy (default: auto-detect)
- `--php-version=` - PHP version for workflow (default: 8.3)

## Individual Commands

### 1. Manual Deployment Only

```bash
php artisan hostinger:deploy
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
- `--token=` - GitHub Personal Access Token (optional, enables automatic deploy key management)

> **Note:** If `GITHUB_API_TOKEN` is provided (via `.env` or `--token` option), the command will automatically add deploy keys to your GitHub repository. Otherwise, you'll be prompted to add the deploy key manually.

---

### 2. Create GitHub Actions Workflow File

```bash
php artisan hostinger:publish-workflow
```

**What it does:** Creates `.github/workflows/hostinger-deploy.yml` file locally

**Required Environment Variables:** None (must be in a Git repository)

**Command Options:**
- `--branch=` - Branch to trigger deployment (default: auto-detect)
- `--php-version=` - PHP version for workflow (default: 8.3)

---

### 3. Setup Automated Deployment (Via GitHub API)

```bash
php artisan hostinger:setup-cicd
```

**What it does:** Publishes GitHub Actions workflow file locally and creates secrets automatically via GitHub API, and automatically adds deploy keys to your repository

**Required Environment Variables:**
- `HOSTINGER_SSH_HOST`
- `HOSTINGER_SSH_USERNAME`
- `HOSTINGER_SSH_PORT`
- `HOSTINGER_SITE_DIR`
- `GITHUB_API_TOKEN`

**Command Options:**
- `--token=` - GitHub Personal Access Token (overrides GITHUB_API_TOKEN from .env)
- `--branch=` - Branch to deploy (default: auto-detect)
- `--php-version=` - PHP version for workflow (default: 8.3)

**GitHub Personal Access Token Permissions:**
Your GitHub Personal Access Token needs the following permissions:
- **Administration** → Read and write (for managing deploy keys for the repository)
- **Metadata** → Read-only (automatically selected, required for API access)

**Note:** The workflow file is published locally to `.github/workflows/hostinger-deploy.yml`. You'll need to review, commit, and push it manually. The command only uses the API to create secrets and deploy keys.

## Environment Variables Summary

| Variable | Required For | Description |
|----------|--------------|-------------|
| `HOSTINGER_SSH_HOST` | All commands | Hostinger server IP address |
| `HOSTINGER_SSH_USERNAME` | All commands | Hostinger SSH username |
| `HOSTINGER_SSH_PORT` | All commands | SSH port (default: 22) |
| `HOSTINGER_SITE_DIR` | All commands | Website folder name |
| `GITHUB_API_TOKEN` | Automated setup | GitHub Personal Access Token (requires Administration permission for deploy keys) |

## Requirements

- PHP ^8.2
- Laravel ^11.0|^12.0
- SSH access to Hostinger server
- Git repository (GitHub recommended)

## License

MIT
