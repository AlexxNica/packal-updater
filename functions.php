<?php

$bundle = 'com.packal';
require_once( __DIR__ . '/init.php' );

function getManifest() {
	global $bundle;

	if ( checkConnection() === false ) {
		return false;
	}

	$dir = DATA_DIR . '/manifest.xml';
	file_put_contents( $dir, file_get_contents( 'https://raw.github.com/packal/repository/master/manifest.xml' ) );

	return true;
}

function firstRun() {
	global $bundle;

	$HOME        = exec( 'echo $HOME' );
	$data        = DATA_DIR;
	$cache       = CACHE_DIR;
	$config      = "$data/config";
	$endpoints   = "$data/endpoints";
	$backups     = "$data/backups";
	$directories = array( $data, $cache, $config, $endpoints, $backups );

	foreach ( $directories as $d ) :
		if ( ! file_exists( $d ) && is_dir( $d ) ) {
			mkdir( $d );
		}
	endforeach;
	unset( $config );

	// Generate Default Config File
	if ( ! file_exists( "$data/config/config.xml" ) ) {
		$d = '<?xml version="1.0" encoding="UTF-8"?><config></config>';
		$config = new SimpleXMLElement( $d );
		$config->packalAccount = 0;
		$config->forcePackal = 0;
		$config->backups = 3;
		$config->username = '';
		$config->authorName = '';
		$config->notifications = '2';
		$config->workflowReporting = '1';
		$config->apiKey = '';
		$config->asXML( "$data/config/config.xml" );
		unset( $config );
	}

	// Generate Empty Blacklist File
	if ( ! file_exists( "$data/config/blacklist.json" ) ) {
		file_put_contents( "$data/config/blacklist.json", utf8_encode( json_encode( array() ) ) );
	}

	if ( ! file_exists( "$data/manifest.xml" ) ) {
		// Get the manifest with error handling.
		if ( getManifest() == false ) {
			// So, we're getting the manifest, but
			// there was a problem, so we're going
			// to communicate that.
			return false;
		}
	}
	return true;
}

function generateEndpoints( $force = false ) {
	global $bundle;

	$HOME = exec( 'echo $HOME' );
	$data = DATA_DIR;

	if ( ! file_exists( "$data/endpoints" ) && is_dir( "$data/endpoints" ) ) {
		mkdir( "$data/endpoints" );
	}

	if ( ( ! ( file_exists( "$data/endpoints/endpoints.json" ) && file_exists( "$data/endpoints/endpoints.list" ) ) )
		|| ( filemtime( "$data/endpoints/endpoints.json" ) < filemtime( dirname( __DIR__ ) ) ) || ( $force !== false ) ) {

		// Okay, we need to update the files.
		$dirs = array_diff( scandir( WORKFLOWS_DIR ), array( '.', '..', '.git', '.DS_Store' ) );

		if ( file_exists( "$data/endpoints/endpoints.list" ) ) {
			unlink( "$data/endpoints/endpoints.list" );
		}
			$fp = fopen( "$data/endpoints/endpoints.list", 'w' );

			$me = basename( __DIR__ );

		foreach ( $dirs as $d ) :
			$d = str_replace( '//', '/', str_replace( $me, '', __DIR__ ) . "/$d" );
			$bundle = readPlistValue( 'bundleid', "$d/info.plist" );
			if ( empty( $bundle ) ) {
				continue;
			}

				$endpoints[ $bundle ] = $d;
				fwrite( $fp , "\"$bundle\"=\"$d\"\n" );
		endforeach;

		file_put_contents( "$data/endpoints/endpoints.json", utf8_encode( json_encode( $endpoints ) ) );
		fclose( $fp );
	} else { 		return false;
	}
}

function readPlistValue( $key, $plist ) {
	return exec( "/usr/libexec/PlistBuddy -c \"Print :$key\" '$plist' 2> /dev/null" );
}

function checkConnection() {
	ini_set( 'default_socket_timeout', 1 );

	// First test
	exec( 'ping -c 1 -t 1 www.github.com', $pingResponse, $pingError );
	if ( $pingError == 14 ) {
		return false;
	}

	// Second Test
	$connection = @fsockopen( 'www.github.com', 80, $errno, $errstr, 1 );

	if ( $connection ) {
		$status = true;
		fclose( $connection );
	} else {
		$status = false;
	}
	return $status;
}

function countFiles( $dir ) {
	$fi = new FilesystemIterator( "$dir" , FilesystemIterator::SKIP_DOTS );
	return iterator_count( $fi );
}

function getFiles( $dir ) {
	return array_diff( scandir( $dir ), array( '..', '.', '.DS_Store' ) );
}

function returnBackups( $dir, &$w ) {
	global $data;

	$backupDirs = getFiles( "$data/backups" );
	foreach ( $backupDirs as $b ) :
		if ( is_dir( "$data/backups/$b" ) ) {
			$backups[ $b ] = countFiles( "$data/backups/$b" );
		}
	endforeach;

	if ( count( $backups ) > 0 ) {
		ksort( $backups );
		foreach ( $backups as $name => $count ) :
			$w->result( '', '', $name, "$count backups.", '', 'no', '' );
		endforeach;
	}
}

function getManifestModTime() {
	global $data;

	// Set date/time things here.
	$m     = time() - filemtime( "$data/manifest.xml" );
	$days  = floor( $m / 86400 );
	$hours = floor( ( $m - ( $days * 86400 ) ) / 3600 );
	$mins  = floor( ( $m - ( $hours * 3600 ) ) / 60 );
	$secs  = floor( $m % 60 );

	if ( $m > 60 * 60 * 24 * 7 * 120 ) {
		return 'a really long time ago.';
	}
	if ( $m > 60 * 60 * 24 * 7 * 30 ) {
		return 'over a month ago.';
	}
	if ( $m > 60 * 60 * 24 * 7 * 30 ) {
		return 'over a week ago.';
	}
	if ( $m > 60 * 60 * 24 ) {
		return 'over a day ago.';
	}
	if ( $m === 0 ) {
		$time = 'just now.';
	}
	$time = '';
	if ( $hours > 0 ) {
		$time .= $hours . ' hour';
		if ( $hours > 1 ) {
			$time .= 's, ';
		} else { $time .= ', ';
		}
	}
	if ( $mins > 0 ) {
		$time .= $mins . ' minute';
		if ( $mins > 1 ) {
			$time .= 's';
		} else { $time .= '';
		}
	}
	if ( $hours > 0 && $mins > 0 ) {
		$time .= ', and ';
	} elseif ( $hours > 0 || $mins > 0 ) {
		$time .= ' and ';
	}
	if ( $secs > 0 ) {
		$time .= $secs . ' second';
		if ( $time > 1 ) {
			$time .= 's';
		}
	}
	$time .= ' ago.';

	return $time;
}

function sortWorkflowByName( $a, $b ) {
	return $a['name'] > $b['name'];
}
