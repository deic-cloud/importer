# importer

Nextcloud app for importing files from remote sources into a user's Nextcloud storage.

## Features

- **Nextcloud 34+**
- **HTTP/HTTPS** — download any publicly accessible file by URL
- **FTP** — browse and download from FTP servers
- **S3 / Object Store** — download from S3-compatible buckets (AWS, MinIO, etc.) with SigV4 authentication
- **WebDAV** — browse and download from WebDAV servers (including other Nextcloud instances)
- Remote directory browser for FTP, S3, and WebDAV — navigate folders and queue individual files or entire trees recursively
- Download queue with configurable parallelism
- Per-download destination folder picker, including support for grant folders (`user_group_admin`)
- Overwrite control: choose at download time whether to overwrite existing files (skipped files are marked as such)
- Credential management in personal settings (stored encrypted per user)

## Requirements

- Nextcloud 34+
- PHP 8.2+

## Installation

Copy the `importer` directory into your Nextcloud `apps/` folder and enable it:

```bash
php occ app:enable importer
```

The pre-built JavaScript bundle is included — no build step required for installation.

## Building from source

```bash
cd apps/importer
npm install
npm run build
```

Requires Node.js 18+ and npm.

## Part of ScienceData

This app is developed for [ScienceData](https://sciencedata.dk), a research data platform for Danish universities. See also the [companion apps](https://github.com/deic-cloud).
