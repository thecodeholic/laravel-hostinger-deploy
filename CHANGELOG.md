# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-01-XX

### Added
- Initial stable release
- `hostinger:deploy-and-setup-automated` - All-in-one command for deployment and automated setup
- `hostinger:deploy-shared` - Manual deployment to Hostinger shared hosting
- `hostinger:auto-deploy` - Setup automated deployment with manual GitHub secrets configuration
- `hostinger:publish-workflow` - Create GitHub Actions workflow file locally
- `hostinger:setup-automated-deploy` - Setup automated deployment via GitHub API
- SSH key generation and management on Hostinger server
- GitHub Actions workflow generation
- GitHub API integration for automatic secret management
- Support for Laravel 11 and 12
- Comprehensive deployment process (composer install, migrations, storage links, optimizations)
- Interactive deployment with conflict resolution
- Git authentication error handling with deploy key setup

### Features
- One-command deployment to Hostinger shared hosting
- Automated GitHub Actions workflow setup
- Manual and automated deployment options
- SSH key management
- Environment variable configuration
- Configurable deployment options via config file

