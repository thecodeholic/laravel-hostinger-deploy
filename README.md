# Laravel Hostinger Deploy

A Laravel package for automated deployment to Hostinger shared hosting with GitHub Actions support.

## Features

- 🚀 **One-command deployment** to Hostinger shared hosting
- 🔄 **GitHub Actions integration** for automated deployments
- 🔑 **Automatic SSH key management** for secure connections
- 📦 **Laravel-specific optimizations** (composer, migrations, storage links)
- ⚙️ **Configurable deployment options** via config file

## Installation

Install the package via Composer:

```bash
composer require zura/laravel-hostinger-deploy --dev
```

## Configuration

### 1. Environment Variables

Add the following variables to your `.env` file:

```env
# Hostinger Deployment Configuration
HOSTINGER_SSH_HOST=your-server-ip
HOSTINGER_SSH_USERNAME=your-username
HOSTINGER_SSH_PORT=22
HOSTINGER_SITE_DIR=your-website-folder
```

### 2. Publish Configuration (Optional)

Publish the configuration file to customize deployment options:

```bash
php artisan vendor:publish --tag=hostinger-deploy-config
```

This will create `config/hostinger-deploy.php` with customizable options.

## Usage

### 1. Deploy to Hostinger

Deploy your Laravel application to Hostinger shared hosting:

```bash
php artisan hostinger:deploy-shared
```

**Options:**
- `--fresh`: Delete existing files and clone fresh repository
- `--site-dir=`: Override site directory from config

### 2. Publish GitHub Actions Workflow

Create a GitHub Actions workflow file for automated deployments:

```bash
php artisan hostinger:publish-workflow
```

**Options:**
- `--branch=`: Override default branch (default: auto-detect)
- `--php-version=`: Override PHP version (default: 8.3)

### 3. Setup Automated Deployment

Configure SSH keys and display GitHub secrets for automated deployment:

```bash
php artisan hostinger:auto-deploy
```

This command will:
- Generate SSH keys on your Hostinger server
- Display all required GitHub secrets and variables
- Provide step-by-step instructions for GitHub setup

## GitHub Actions Setup

After running `php artisan hostinger:auto-deploy`, you'll need to add the following to your GitHub repository:

### Secrets (Repository Settings → Secrets and variables → Actions → Secrets)

- `SSH_HOST`: Your Hostinger server IP address
- `SSH_USERNAME`: Your Hostinger SSH username  
- `SSH_PORT`: Your Hostinger SSH port (usually 22)
- `SSH_KEY`: Your private SSH key (displayed by the command)

### Variables (Repository Settings → Secrets and variables → Actions → Variables)

- `WEBSITE_FOLDER`: Your Hostinger website folder name

### Deploy Keys (Repository Settings → Deploy keys)

Add the public SSH key displayed by the `auto-deploy` command as a deploy key.

## Workflow

The generated GitHub Actions workflow will:

1. **Checkout code** from your repository
2. **Setup PHP** environment
3. **Install dependencies** via Composer
4. **Generate application key** and create storage link
5. **Run database migrations**
6. **Deploy to Hostinger** via SSH
7. **Update code** on the server and run optimizations

## Configuration Options

### SSH Settings

```php
'ssh' => [
    'host' => env('HOSTINGER_SSH_HOST'),
    'username' => env('HOSTINGER_SSH_USERNAME'),
    'port' => env('HOSTINGER_SSH_PORT', 22),
    'timeout' => 30,
],
```

### Deployment Settings

```php
'deployment' => [
    'site_dir' => env('HOSTINGER_SITE_DIR'),
    'composer_flags' => '--no-dev --optimize-autoloader',
    'run_migrations' => true,
    'run_storage_link' => true,
    'run_config_cache' => false,
    'run_route_cache' => false,
    'run_view_cache' => false,
],
```

### GitHub Actions Settings

```php
'github' => [
    'workflow_file' => '.github/workflows/hostinger-deploy.yml',
    'php_version' => '8.3',
    'default_branch' => 'main',
],
```

## Requirements

- PHP ^8.2
- Laravel ^11.0|^12.0
- SSH access to Hostinger server
- Git repository with GitHub integration

## Troubleshooting

### SSH Connection Issues

1. Verify your SSH credentials in `.env`
2. Test SSH connection manually: `ssh -p PORT USERNAME@HOST`
3. Ensure SSH key authentication is working

### GitHub Actions Issues

1. Verify all secrets and variables are correctly set
2. Check that the deploy key is added to your repository
3. Ensure the workflow file is committed and pushed

### Deployment Issues

1. Check that your Hostinger server has Composer installed
2. Verify the site directory exists and is writable
3. Ensure your Laravel application is properly configured

## Security Notes

- SSH keys are generated on the server and should be kept secure
- Private keys are displayed for GitHub setup - copy them carefully
- The package uses SSH key authentication for secure deployments

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Support

If you encounter any issues or have questions, please open an issue on GitHub.
