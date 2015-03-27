<?php

/**
 * @author Ben Johnson (ben@indietorrent.org)
 * @copyright Copyright (c) 2012, Ben Johnson
 * @license GNU General Public License, Version 3 (GPLv3)
 * @version 1.0
 */

$configFile = __DIR__ . DIRECTORY_SEPARATOR . 'db-backup.ini';

$conf = parse_ini_file($configFile, TRUE);

if ($conf === FALSE) {
	die('Configuration values could not be read from "' . $configFile . '"; ensure that the file exists and contains valid configuration parameters' . PHP_EOL);
}

$mysqli = new mysqli($conf['connection']['hostname'], $conf['connection']['username'], $conf['connection']['password']);

if ($mysqli->connect_error) {
	die('Connect Error (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
}

$q = 'SHOW DATABASES';

$r = $mysqli->query($q);

while ($row = $r->fetch_assoc()) {
	$targetDir = $conf['backup']['dumpdir'] . DIRECTORY_SEPARATOR . $row['Database'];
	
	if (!is_dir($targetDir)) {
		$madeDir = mkdir($targetDir);
		
		if ($madeDir === FALSE) {
			echo 'The directory "' . $targetDir . '" does not exist and could not be created; please check the permissions';
			exit;
		}
	}
	
	$dumpFileName = $targetDir . DIRECTORY_SEPARATOR . date('YmdHi') . '.sql';
	
	//The --skip-comments switch is for the hash checks (below) to work correctly,
	//for obvious reasons (the comments include a timestamp at the very end of the
	//file, which will result in a different hash, even when the actual DB content
	//has not changed).
	
	//Also, the dump file cannot be compressed before its hash is checked, because
	//each compression attempt yields a different file (even with identical input).
	
	$cmd = 'mysqldump --skip-comments --add-drop-table --default-character-set=utf8 --extended-insert --host=localhost --quick --quote-names --routines --set-charset --single-transaction --triggers --tz-utc --verbose --user=root --password=\'' . $conf['connection']['password'] . '\' "' . $row['Database'] . '" > "' . $dumpFileName . '"';
	
	$return = system($cmd);
	
	if ($return != 0 || !file_exists($dumpFileName)) {
		echo 'The database "' . $row['Database'] . '" could not be dumped; exiting to prevent unexpected results' . PHP_EOL;
		exit;
	}
	else {
		echo 'The database "' . $row['Database'] . '" was dumped successfully to ' . $dumpFileName . PHP_EOL;
		
		$newFile = $dumpFileName;
		
		//Check the newly-created file's hash; unless it differs from the previous
		//backup file's hash, the DB contents haven't changed and we should discard
		//this backup so as not to waste precious storage space.
		
		//In PHP <= 5.4, any non-zero value, when passed as the second argument
		//to scandir(), will force descending sort order.
		
		$existing = array_diff(scandir($targetDir, 1), array('..', '.'));
		
		if (!empty($existing) && is_array($existing) && count($existing) > 1) {
			for ($i = 0; $i < 2; $i++) {
				$thisFile = $targetDir . DIRECTORY_SEPARATOR . $existing[$i];
				
				if (pathinfo($thisFile, PATHINFO_EXTENSION) === 'gz') {
					echo 'More than one backup exists; checking hashes to see if this backup differs from the last...' . PHP_EOL;
					
					$oldFile = $thisFile;
					
					break;
				}
			}
			
			if (isset($oldFile)) {
				$cmd = 'gunzip "' . $oldFile . '" --to-stdout | sha1sum -';
				
				echo "Unpacking previous backup and acquiring hash with '" . $cmd . "'..." . PHP_EOL;
				
				$oldHash = system($cmd);
				
				$cmd = 'sha1sum "' . $newFile . '"';
				
				echo "Checking new file hash with '" . $cmd . "'..." . PHP_EOL;
				
				$newHash = system($cmd);
				
				if (substr($oldHash, 0, 40) === substr($newHash, 0, 40)) {
					echo 'This dump matches the previous dump exactly (checked by SHA1 hash); discarding this backup, as it contains nothing new' . PHP_EOL;
					
					unlink($newFile);
					
					continue;
				}
				else {
					echo 'The two files are not the same; this backup contains new information' . PHP_EOL;
				}
			}
		}
		
		//GZIP the file, now that we have elected to keep it.
		
		$gzipped = gzipFile($newFile);
		
		if ($gzipped === FALSE) {
			echo 'gzipping "' . $newFile . '" failed; keeping uncompressed file as a fall-back' . PHP_EOL;
		}
		else {
			echo 'File was gzipped successfully to ' . $gzipped . PHP_EOL;
		}
	}
}

$mysqli->close();

function gzipFile($file) {
	$cmd = 'gzip "' . $file . '"';
	
	$return = system($cmd);
	
	if ($return != 0) {
		return FALSE;
	}
	else {
		return $file . '.gz';
	}
}
