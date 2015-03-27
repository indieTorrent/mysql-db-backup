# indieTorrent.org

## mysql-db-backup

## Abstract

A PHP script whose purpose is to automate the routine export of MySQL databases.

There are numerous improvements that could be made to this script, but as of its initial public release, it is suitable for production use.

## Requirements

This script is designed for and has been tested on GNU/Linux operating systems, but it should function equally well on Mac OS X, UNIX variants, or Windows (provided Cygwin is installed).

For this script to function properly, it is necessary for the following executables to be available within the environment in which the script is executed:

1. mysqldump
2. gzip
3. gunzip
4. sha1sum

## Overview

This script attempts to connect to a local MySQL instance, query the list of available databases, and iterate over them, dumping each database (using the `mysqldump` command that is provided with MySQL).

The script files database dumps into their own database-named directories, which makes it easy to find the latest backup (or any earlier backup, for that matter) if the need to restore arises.

To prevent wasteful backups, the script checks the just-dumped backup against the previous backup to determine if both backups are identical; if they are, the just-dumped backup is discarded, as this indicates that no new data has been added to the target database since it was last backed-up.