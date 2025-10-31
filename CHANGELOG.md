# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0] - 2025-01-XX

### Added
- Enhanced error handling with detailed error messages showing exit codes and command output
- `--show-errors` flag for `hostinger:deploy` command to display verbose error information
- Automatic Git host key verification: Automatically adds Git repository host (GitHub, GitLab, etc.) to `known_hosts` to prevent interactive prompts during first-time cloning
- Support for all Git hosting providers: Extracts hostname from repository URL automatically (GitHub, GitLab, Bitbucket, etc.)

### Improved
- Error messages now include exit codes, error output (stderr), and regular output (stdout) from failed SSH commands
- Deployment failures now provide actionable debugging information instead of generic error messages
- Fixed first-time repository cloning issue where SSH would prompt for host key verification
- Better error visibility: Errors with detailed information are automatically shown even without the `--show-errors` flag

### Documentation
- Added requirement documentation for PHP `exec()` function in README
- Added SSH public key authentication setup recommendations and instructions
- Moved environment variables section to the top of README for better visibility
- Enhanced README with clearer setup instructions and security best practices

### Fixed
- Fixed deployment failure on first-time git clone due to host key verification prompt
- Improved error reporting when deployment commands fail with non-zero exit codes

## [0.2.0] - 2025-10-31

### Added
- Automatic npm build support: `hostinger:deploy` now automatically detects `package.json` and builds frontend assets locally before deployment
- Built asset copying: Automatically copies `public/build/` directory to the remote server using rsync after deployment
- GitHub Actions workflow enhancements:
  - SSH key installation step for secure authentication
  - Automatic copying of built frontend assets to the remote server
  - Support for projects with npm/frontend build requirements

### Changed
- Refactored all commands to extend `BaseHostingerCommand` base class for better code organization and DRY principles
- Renamed `hostinger:deploy-shared` to `hostinger:deploy` (simpler, more intuitive name)
- Renamed `hostinger:setup-automated-deploy` to `hostinger:setup-cicd` (more accurate terminology)
- Renamed `hostinger:deploy-and-setup-automated` to `hostinger:deploy-and-setup-cicd` (consistent naming)

### Improved
- Reduced code duplication by extracting common methods into `BaseHostingerCommand`
- Improved maintainability - changes to shared logic now only need to be made once
- Better code organization with centralized configuration validation, SSH setup, and GitHub API initialization
- Frontend assets are now built locally (no npm required on shared hosting servers)

## [0.1.0] - 2025-10-31

### Added
- Initial stable release
- `hostinger:deploy-and-setup-automated` - All-in-one command for deployment and automated setup
- `hostinger:deploy-shared` - Manual deployment to Hostinger shared hosting
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

