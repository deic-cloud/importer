# importer

Nextcloud app for importing files from remote sources into a user's Nextcloud storage.

## Features

- **Nextcloud 34+**
- **HTTP/HTTPS** — download any publicly accessible file by URL
- **FTP** — browse and download from FTP servers
- **S3 / Object Store** — download from S3-compatible buckets (AWS, MinIO, etc.) with SigV4 authentication
- **WebDAV** — browse and download from WebDAV servers (including other Nextcloud instances)
- **SFTP** — browse and download from SFTP servers (HPC and lab systems, ERDA, …). Log in with a password, a pasted private key (RSA only - phpseclib 2, which Nextcloud bundles, cannot read ed25519/ECDSA keys; optional passphrase; checked when saved, stored encrypted like a password) or **the user's own certificate key** (files_sharding's personal certificate, fetched with `CertificateService::getKeyPem` when connecting and never stored by the importer; the settings page links to its public key for `~/.ssh/authorized_keys`; offered only when files_sharding is enabled and the user has a certificate). Addresses: `sftp://host[:port]/path`, where `/` means the login's home folder. Uses phpseclib, which Nextcloud bundles; files are read in 4 MB requests (`SftpReadStream`), not 8 kB round trips. The server's host key is remembered on first contact (per host and port, for the whole instance, app config `sftp_hostkey_<host>:<port>`) and a changed key is refused, as `ssh` does with `known_hosts`; after a legitimate change an admin runs `occ config:app:delete importer sftp_hostkey_<host>:<port>`. Like the HTTP sources, no local or internal addresses unless `allow_local_remote_servers` is set
- **Hugging Face** — browse public models and datasets on the Hugging Face Hub (`https://huggingface.co/<owner>` lists an owner's repositories, `https://huggingface.co/[datasets/]<owner>/<name>` a repository's files) and queue a whole repository or organisation; gated and private repositories are not supported
- Remote directory browser for FTP, S3, WebDAV, SFTP and Hugging Face — navigate folders and queue individual files or entire trees recursively
- Download queue with configurable parallelism (single files, run from the page)
- **Whole folders as one job** — "Import this folder" in the remote browser queues one job for everything below it. While the Import page is open, the page drives it (`POST /api/v1/jobs/{id}/step`: up to 45 s per request, checked between files, then the job goes back to the queue and the page asks for the next step), so a small folder is done seconds after the click. Close the page and the background job (cron) continues from there; a job the background job or another tab holds is left to it, and the page takes over again when it is handed back. Each pass lists the source and downloads what is missing or has a different size, so it continues after any interruption and a re-run fetches only what changed. A background pass works for up to `background_minutes` (app setting, default 15); the page shows "x of N files · a / b" and the file being downloaded; one notification comes when a background pass finishes the job
- Byte progress for running downloads, written every few seconds; a job marked running that shows no progress for 15 minutes (e.g. a download killed by a web-server restart) goes back in the queue
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
