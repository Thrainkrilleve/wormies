# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.2] - 2026-11-22

### Added

- Scheduled import of Thera and Turnur connections from EVE-Scout, every 30 minutes. It is off by default and is turned on per map in General settings. Only holes within five jumps of the map's home system or a pinned system are added unless a manager chooses another limit. Holes this import creates are updated and removed with EVE-Scout. Holes added by hand are left alone. ([#21](https://github.com/Thrainkrilleve/wormies/pull/21))

### Changed

- The Alliance Auth plugin reports its version from the git tag it was installed from. ([#18](https://github.com/Thrainkrilleve/wormies/pull/18))

### Fixed

- The live map stays connected. Allowed websocket origins are matched by host, so an address written with `https://` no longer closes the socket as "Origin not allowed". ([#19](https://github.com/Thrainkrilleve/wormies/pull/19))
- Alliance Auth can refresh a copied EVE token again. The character-token route skips the login page, and a redirect or a non-JSON reply is treated as a failure. ([#20](https://github.com/Thrainkrilleve/wormies/pull/20))

## [0.1.1] - 2026-11-22

### Security

- GitHub Actions workflows ask only for read access to repository contents. ([#17](https://github.com/Thrainkrilleve/wormies/pull/17))

## [0.1.0] - 2026-11-22

First tagged release of this fork. Everything before this tag, from the original map through Alliance Auth sign-in, Discord access, and the self-hosted image, is summarized here instead of listed commit by commit.

### Added

- Shared wormhole maps, with signatures, routes, and EVE-Scout connections added by hand.
- Sign-in through Alliance Auth, including copied EVE tokens that are kept when a refresh fails.
- Discord access for a linked server, nicknames taken from the main character, and map editing for members.
- A self-hosted image that serves the map and its live updates.

### Changed

- The Alliance Auth package version is stamped from the git tag.

### Fixed

- Character tracking no longer stalls after a quiet period.
- Signing in no longer lands on the raw text of a background request.
- Scripts added by the frontend build are covered by the page's content security policy.

### Security

- The content security policy stays strict. Scripts need a nonce, and the live-update host is limited to this install.
- Dependency patches for issues reported in the development toolchain. ([#16](https://github.com/Thrainkrilleve/wormies/pull/16))
- The published image pins its language runtime and does not store sessions on the container disk.

[Unreleased]: https://github.com/Thrainkrilleve/wormies/compare/v0.1.2...HEAD
[0.1.2]: https://github.com/Thrainkrilleve/wormies/compare/v0.1.1...v0.1.2
[0.1.1]: https://github.com/Thrainkrilleve/wormies/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/Thrainkrilleve/wormies/releases/tag/v0.1.0
