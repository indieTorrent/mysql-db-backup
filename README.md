# MySQL Database Backup

A PHP CLI tool for automated MySQL/MariaDB database backups with intelligent deduplication and compression.

## Features

- ✅ **Automated backups** of all databases (excluding system databases)
- ✅ **Intelligent deduplication** - skips backups if database content hasn't changed (SHA1 hash comparison)
- ✅ **Automatic compression** - gzip compression of backup files
- ✅ **Organized storage** - separate directories for each database
- ✅ **Cron-ready** - designed for scheduled execution
- ✅ **Docker support** - includes Dockerfile for containerized usage
- ✅ **Flexible configuration** - INI file or environment variables

## Requirements

- PHP 8.0 or higher (for native installation)
- `mysqli` PHP extension (for native installation)
- **Docker** (for containerized installation - recommended)
- MySQL or MariaDB server
- System executables: `mysqldump`, `gzip`, `gunzip`, `sha1sum` (included in Docker image)

### Docker Setup Prerequisites

If using Docker (recommended), ensure your user has Docker permissions:

```bash
# Add your user to the docker group
sudo usermod -aG docker your_username

# Log out and back in, or run:
newgrp docker

# Verify Docker access (should work without sudo)
docker ps
```

## Database User Setup

### Choose Your Deployment Scenario

Pick the option that matches your setup:

| Scenario | User Setup | Network Mode | Best For |
|----------|-----------|--------------|----------|
| **A: Localhost MySQL** | `'backup'@'localhost'` | `--network host` | Single server, MySQL on localhost (simplest) |
| **B: Isolated Container** | `'backup'@'%'` | `bridge` (default) | Better isolation, any Docker setup |
| **C: Multi-Container** | `'backup'@'%'` | Custom network | MySQL in another container |

### Scenario A: Localhost MySQL (Recommended for Single Server)

**Best for:** MySQL/MariaDB running on the same server as Docker

```sql
-- Log in to MySQL/MariaDB as root
sudo mysql

-- Create backup user for localhost connections
CREATE USER 'backup'@'localhost' IDENTIFIED BY 'your_secure_password';

-- Grant necessary permissions for full database backups
GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT ON *.* TO 'backup'@'localhost';
GRANT SELECT ON mysql.proc TO 'backup'@'localhost';

FLUSH PRIVILEGES;
EXIT;

-- Test it
mysql -u backup -p
```

**Configuration (.env):**
```bash
DOCKER_NETWORK_MODE=host
DB_HOST=127.0.0.1
DB_USERNAME=backup
DB_PASSWORD=your_secure_password
```

**Important:** Use `127.0.0.1`, NOT `localhost`! See `.env.example` for explanation.

**Security:** Most secure - only accepts connections from localhost

### Scenario B: Bridge Network (Better Isolation)

**Best for:** When you want container isolation or don't want to use `--network host`

```sql
sudo mysql

-- Create backup user that accepts connections from any IP
-- Still secure because it requires password and MySQL should be firewalled
CREATE USER 'backup'@'%' IDENTIFIED BY 'your_strong_password';

-- Or restrict to Docker networks only (more restrictive)
-- CREATE USER 'backup'@'172.%' IDENTIFIED BY 'your_strong_password';

GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT ON *.* TO 'backup'@'%';
GRANT SELECT ON mysql.proc TO 'backup'@'%';

FLUSH PRIVILEGES;
EXIT;
```

**Configuration (.env):**
```bash
DOCKER_NETWORK_MODE=bridge
DB_HOST=172.17.0.1        # Docker bridge gateway (usually stable)
# Or on some systems: DB_HOST=host.docker.internal
DB_USERNAME=backup
DB_PASSWORD=your_strong_password
```

**Security:** Good - relies on password auth and firewall. Ensure MySQL is not exposed to internet!

### Scenario C: Multi-Container Setup

**Best for:** MySQL running in another Docker container

```sql
-- Same as Scenario B
CREATE USER 'backup'@'%' IDENTIFIED BY 'your_strong_password';
GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT ON *.* TO 'backup'@'%';
GRANT SELECT ON mysql.proc TO 'backup'@'%';
FLUSH PRIVILEGES;
```

**Configuration (.env):**
```bash
DOCKER_NETWORK_MODE=your_mysql_network_name
DB_HOST=mysql_container_name  # Name of your MySQL container
DB_USERNAME=backup
DB_PASSWORD=your_strong_password
```

### Quick Reference: Permission Grants

The minimum permissions needed for backups:

```sql
-- Read all tables
GRANT SELECT ON *.* TO 'backup'@'...';

-- Lock tables during backup (for consistency)
GRANT LOCK TABLES ON *.* TO 'backup'@'...';

-- Backup views
GRANT SHOW VIEW ON *.* TO 'backup'@'...';

-- Backup triggers
GRANT TRIGGER ON *.* TO 'backup'@'...';

-- Backup events (scheduled tasks)
GRANT EVENT ON *.* TO 'backup'@'...';

-- Backup stored procedures/functions
GRANT SELECT ON mysql.proc TO 'backup'@'...';
```

## Quick Start (Docker - Recommended)

Complete setup in 5 minutes for localhost MySQL:

```bash
# 1. Ensure user has Docker permissions
sudo usermod -aG docker $USER
newgrp docker  # Or log out/in

# 2. Create backup user in MariaDB/MySQL
sudo mysql <<EOF
CREATE USER 'backup'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT SELECT, LOCK TABLES, SHOW VIEW, TRIGGER, EVENT ON *.* TO 'backup'@'localhost';
GRANT SELECT ON mysql.proc TO 'backup'@'localhost';
FLUSH PRIVILEGES;
EOF

# 3. Clone and setup
git clone https://github.com/indiehd/mysql-db-backup.git
cd mysql-db-backup
git checkout composer  # Or master after merge

# 4. Build Docker image
docker build -t mysql-db-backup -f docker/Dockerfile .

# 5. Configure credentials
cat > .env <<EOF
DOCKER_NETWORK_MODE=host
DB_HOST=127.0.0.1
DB_USERNAME=backup
DB_PASSWORD=your_secure_password
EOF
chmod 600 .env

# 6. Test backup
./run-backup.sh

# 7. Schedule daily backups (2 AM)
crontab -e
# Add: 0 2 * * * /path/to/mysql-db-backup/run-backup.sh >> /var/log/mysql-backup.log 2>&1

# 8. (Optional) Add retention - keep 30 days
# Add: 0 3 * * 0 find /path/to/mysql-db-backup/backups -name "*.sql.gz" -mtime +30 -delete
```

Done! Your backups will run daily at 2 AM. ✅

## Installation

### Via Composer (Recommended)

```bash
composer require indiehd/mysql-db-backup
```

### Manual Installation

```bash
git clone https://github.com/indiehd/mysql-db-backup.git
cd mysql-db-backup
composer install
```

## Configuration

### Option 1: Configuration File (Recommended)

Copy the example configuration file and customize it:

```bash
cp db-backup.ini.example db-backup.ini
```

Edit `db-backup.ini`:

```ini
[connection]
hostname = localhost
port = 3306
username = your_mysql_user
password = your_mysql_password
; ssl_ca = /etc/ssl/certs/db-ca.pem

[backup]
dumpdir = /path/to/backups
; databases = app,analytics
; dump_options = --set-gtid-purged=OFF
```

**Note:** The `password` field is optional if you're using socket-based authentication (e.g., `auth_socket` plugin in MariaDB).

### Option 2: Environment Variables

You can also configure using environment variables:

```bash
export DB_HOST=127.0.0.1      # Use 127.0.0.1, not localhost (for Docker)
export DB_PORT=3306           # Optional; managed databases often use another port
export DB_USERNAME=your_mysql_user
export DB_PASSWORD=your_mysql_password
export DB_SSL_CA=/path/to/ca.pem          # Optional; require TLS and verify the server against this CA
export DB_DATABASES=app,analytics         # Optional; default is every non-system database
export MYSQLDUMP_EXTRA_OPTIONS='--set-gtid-purged=OFF'  # Optional; appended to mysqldump as-is
export BACKUP_DIR=/path/to/backups
```

### Managed / hosted databases

Managed MySQL services (DigitalOcean, RDS, Cloud SQL, ...) typically listen on a
non-standard port, require TLS, and expose a `defaultdb` alongside your own
database. `DB_PORT` and `DB_SSL_CA` cover the first two; `DB_DATABASES` pins the
backup to the databases you actually own.

When `DB_SSL_CA` is set, the server certificate is verified against that file
both for the connection that lists databases and for each `mysqldump` run. The
verification flag differs between MySQL's `mysqldump` (`--ssl-mode=VERIFY_CA`)
and MariaDB's (`--ssl-verify-server-cert`); the tool detects which client is
installed and passes the right one.

If your server has GTIDs enabled and you dump it with MySQL's own `mysqldump`,
add `--set-gtid-purged=OFF` via `MYSQLDUMP_EXTRA_OPTIONS`, or the dump will
carry a `SET @@GLOBAL.GTID_PURGED` statement that a plain restore user cannot
execute. MariaDB's `mysqldump` never emits that statement and rejects the flag.

Dumps are taken with `--default-character-set=utf8mb4`. Earlier releases used
`utf8`, which MySQL and MariaDB treat as the 3-byte `utf8mb3`: the server
transcoded results on the wire and every 4-byte character (emoji, many CJK
ideographs) reached the dump as a literal `?`. If your data contains such
characters, dumps made before this change are lossy for them.

## Usage

### Command Line

If installed via Composer:

```bash
./vendor/bin/mysql-db-backup
```

If installed globally or from project directory:

```bash
./bin/mysql-db-backup
```

### Cron Job Setup

To run automated backups daily at 2 AM:

```bash
crontab -e
```

Add this line:

```cron
0 2 * * * cd /path/to/project && ./vendor/bin/mysql-db-backup >> /var/log/mysql-backup.log 2>&1
```

Or if using environment variables:

```cron
0 2 * * * DB_HOST=localhost DB_USERNAME=backup_user DB_PASSWORD=secret BACKUP_DIR=/backups /path/to/vendor/bin/mysql-db-backup >> /var/log/mysql-backup.log 2>&1
```

### Docker Usage

**For complete Docker deployment guide, see [DOCKER.md](DOCKER.md)**

#### Quick Start with Docker

```bash
# 1. Clone and build
git clone https://github.com/indiehd/mysql-db-backup.git
cd mysql-db-backup
git checkout composer
docker build -t mysql-db-backup -f docker/Dockerfile .

# 2. Create environment file (configure based on your scenario - see Database User Setup above)
cp .env.example .env
nano .env  # Edit with your credentials and network mode
chmod 600 .env

# 3. Test one-time backup
./run-backup.sh

# 4. Set up scheduled backups with cron
crontab -e
# Add: 0 2 * * * /path/to/mysql-db-backup/run-backup.sh >> /var/log/mysql-backup.log 2>&1
```

**The `run-backup.sh` script automatically handles different network modes based on your `.env` configuration.**

#### Manual Docker Run (if not using run-backup.sh)

**Scenario A - Host Network:**
```bash
docker run --rm --network host --env-file .env \
  -v $(pwd)/backups:/backups mysql-db-backup
```

**Scenario B - Bridge Network:**
```bash
docker run --rm --env-file .env \
  -v $(pwd)/backups:/backups mysql-db-backup
```

**Scenario C - Custom Network:**
```bash
docker run --rm --network your_network --env-file .env \
  -v $(pwd)/backups:/backups mysql-db-backup
```

#### Using docker-compose

```bash
# Edit .env file first, then:
docker-compose up
```

## How It Works

1. **Connect** to MySQL/MariaDB server
2. **List** all databases (excluding system databases: `mysql`, `information_schema`, `performance_schema`, `sys`)
3. **Dump** each database using `mysqldump` with optimized flags
4. **Compare** the new backup hash with the previous backup
5. **Skip** if identical (no changes detected)
6. **Compress** with gzip if new data is present
7. **Store** in organized directory structure: `<dumpdir>/<database>/<YYYYMMDDHHmm>.sql.gz`

## Backup Optimization

The tool uses SHA1 hash comparison to avoid storing duplicate backups when database content hasn't changed. This saves significant storage space for databases that don't change frequently.

The dump files are created **without comments** (via `--skip-comments`) to ensure hash comparison works correctly, as comments include timestamps that would always differ.

## Directory Structure

```
/path/to/backups/
├── database1/
│   ├── 202602251400.sql.gz
│   ├── 202602261400.sql.gz
│   └── 202602271400.sql.gz
├── database2/
│   ├── 202602251400.sql.gz
│   └── 202602271400.sql.gz  # 26th skipped - no changes
└── database3/
    └── 202602251400.sql.gz
```

## Backup Retention

**Important:** This tool does NOT automatically delete old backups. Backups will accumulate over time.

### Recommended: Add a Cleanup Cron Job

To prevent unlimited backup growth, add a retention policy:

```cron
# Keep backups for 30 days (weekly cleanup)
0 3 * * 0 find /path/to/backups -name "*.sql.gz" -mtime +30 -delete
```

**Common retention periods:**
- Development: 7-14 days
- Production: 30-90 days
- Compliance: May require longer (consult your requirements)

**Future Enhancement:** Built-in retention policies are planned for a future release.

## Troubleshooting

### Permission Issues

Ensure the backup directory is writable:

```bash
chmod 755 /path/to/backups
```

### MySQL Connection Issues

Test your connection:

```bash
mysql -h localhost -u your_user -p
```

### Missing Dependencies

Verify required executables are available:

```bash
which mysqldump gzip gunzip sha1sum
```

## License

GNU General Public License v3.0 or later (GPL-3.0-or-later)

## Author

Ben Johnson (ben@indiehd.com)

Copyright (c) 2012-2026, Ben Johnson
