# Changelog

All notable changes to this project will be documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]
### Added
- Feature X for dynamic pass creation.
- QR code rotation for better security.
- Metadata field mapping for Google Wallet pass customization.

### Fixed
- Bug fix related to product category pass generation.

## [1.0.0] - 2025-02-06
### Added
- Initial release of **Passify-Pro** with the following features:
  - Google Wallet ticket integration.
  - Secure service account key storage with encryption.
  - Rotating QR codes for redemption.
  - WooCommerce integration for product-based pass creation.
  - Metadata field configuration for dynamic pass creation.
  - Admin interface for service account key upload and metadata field selection.
  - Log viewing functionality for admins.
  - Secure redemption API for ticket validation by authorized roles.

### Changed
- Refined the admin settings page for smoother UX.

### Security
- Ensured service account key is only decrypted during API calls for better security.

## [0.1.0] - 2025-01-15
### Added
- **Initial framework** setup and base structure.
- Google Wallet API integration (untested).
- Preliminary admin interface design.

### Changed
- Reorganized plugin folder structure for maintainability.

## [0.0.1] - 2025-01-01
### Added
- Initial development started, setting up the project structure and dependencies.
