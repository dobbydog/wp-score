<?php
namespace WPScore;

use Composer\Script\Event;

class Script {
	/**
	 * [preInstall description]
	 * @param  \Composer\Script\Event  $e
	 * @return int
	 */
	public static function preInstall( Event $e ) {
		$io = $e->getIO();
		$cli = new CliWrapper( $io );

		// check if wp is installed
		if( $cli->checkInstalled() ) {
			$io->write( 'WordPress is installed. Nothing to do.' );
			return 0;
		}

		try {
			if( !file_exists('wp-load.php') ) {
				$cli->download();
			}

			if( !file_exists('wp-config.php') ) {
				$cli->config();
				$cli->createDB();
				$cli->createDBUser();
			}

			$cli->install();
		}
		catch( \Exception $exception ) {
			throw $exception;
		}

		return 0;
	}
}
