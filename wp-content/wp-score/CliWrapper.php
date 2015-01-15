<?php
namespace WPScore;

use Composer\IO\IOInterface;

class CliWrapper {
	const WP_CLI_PHAR_URL = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';
	public $io;
	protected $wpCli;
	protected $pdo;

	/**
	 * [__construct description]
	 * @param IOInterface $io [description]
	 */
	public function __construct( IOInterface $io ) {
		$this->io = $io;

		// check wp-cli executable and download phar if not exists
		$this->wpCli = exec( 'which wp 2>&1', $_, $ret );
		
		if( $ret !== 0 ) {
			file_put_contents( './wp-cli.phar', file_get_contents( self::WP_CLI_PHAR_URL ) );
			$this->wpCli = 'php wp-cli.phar';
		}
	}

	public function checkInstalled() {
		return !$this->wpCli( 'core is-installed', array(), null, false );
	}

	/**
	 * [download description]
	 * @throws \RuntimeException If [this condition is met]
	 * @return boolean
	 */
	public function download() {
		$this->io->write( '### Download WordPress' );

		return $this->wpCli( 'core download', array( 'locale' => 'ja' ) );
	}

	/**
	 * [config description]
	 * @throws \RuntimeException If [this condition is met]
	 * @return boolean
	 */
	public function config() {
		$this->io->write( '### Configure WordPress' );

		$this->conf_args = array(
			'dbname' => $this->io->askAndValidate(
				'* DB name: ',
				self::getValidator( array( 'required', 'alnumunder' ) )
			),
			'dbuser' => $this->io->askAndValidate(
				'* DB user: ',
				self::getValidator( array( 'required', 'alnumunder' ) )
			),
			'dbpass' => $this->io->askAndHideAnswer(
				'* DB pass (input will be hidden): '
			),
			'dbhost' => $this->io->ask(
				'* DB host [localhost]: ',
				'localhost'
			),
			'dbprefix' => $this->io->askAndValidate(
				'* DB prefix [wp_]: ',
				self::getValidator( 'alnumunder' ),
				false,
				'wp_'
			),
			'dbcharset' => $this->io->askAndValidate(
				'* DB character set [utf8]: ',
				self::getValidator( 'alnumunder' ),
				false,
				'utf8'
			),
			'dbcollate' => $this->io->askAndValidate(
				'* DB collate []: ',
				self::getValidator( 'alnumunder' ),
				false,
				''
			),
			'extra-php' => null,
			'skip-check' => null,
		);

		$extra_php = 'if( file_exists( $autoloader = dirname(__FILE__) . \'/vendor/autoload.php\' ) ) require $autoloader;';

		return $this->wpCli( 'core config', $this->conf_args, $extra_php );
	}

	/**
	 * [createDB description]
	 * @throws \RuntimeException If [this condition is met]
	 * @return boolean
	 */
	public function createDB() {
		$this->io->write( '### Create Database' );

		$creator_user = $this->io->ask(
			'* MySQL user to create database: ',
			$this->conf_args['dbuser']
		);
		$creator_pass = $this->io->askAndHideAnswer(
			'* Password for user above: ',
			$this->conf_args['dbpass']
		);

		try {
			$this->pdo = new \PDO( 'mysql:host='.$this->conf_args['dbhost'], $creator_user, $creator_pass );
		}
		catch( \PDOException $exception ) {
			$this->io->write( $exception->getMessage() );
			$this->discardProgress('config');

			throw new \RuntimeException( '[PDO] connect database failed.' );
		}

		$create_sql =
			"CREATE DATABASE `{$this->conf_args['dbname']}` ".
			"DEFAULT CHARACTER SET `{$this->conf_args['dbcharset']}`";

		if( $this->conf_args['dbcollate'] !== '' ) {
			$create_sql .= " DEFAULT COLLATE `{$this->conf_args['dbcollate']}`";
		}

		if( $this->pdo->exec( $create_sql ) === false ) {
			$this->io->write( print_r( $this->pdo->errorInfo(), true ) );
			$this->discardProgress('config');
			
			throw new \RuntimeException( '[PDO] create database failed.' );
		}

		return true;
	}

	/**
	 * [createDBUser description]
	 * @throws \RuntimeException If [this condition is met]
	 * @return boolean
	 */
	public function createDBUser() {
		$grant_sql =
			"GRANT ALL PRIVILEGES ON `{$this->conf_args['dbname']}`.* ".
			"TO `{$this->conf_args['dbuser']}`@`localhost`";

		if( $this->conf_args['dbpass'] !== '' ) {
			$grant_sql .= " IDENTIFIED BY ".$this->pdo->quote( $this->conf_args['dbpass'] );
		}

		if( $this->pdo->exec( $grant_sql ) === false ) {
			$this->io->write( print_r( $this->pdo->errorInfo(), true ) );
			$this->discardProgress('db');

			throw new \RuntimeException( '[PDO] create database user failed.' );
		}

		return true;
	}

	/**
	 * [install description]
	 * @throws \RuntimeException If [this condition is met]
	 * @return boolean
	 */
	public function install() {
		$this->io->write( '### Install WordPress' );

		$inst_args = array(
			'url' => $this->io->askAndValidate(
				'* Site URL: ',
				self::getValidator( 'required' )
			),
			'title' => $this->io->askAndValidate(
				'* Site name: ',
				self::getValidator( 'required' )
			),
			'admin_user' => $this->io->askAndValidate(
				'* Admin user name: ',
				self::getValidator( 'required' )
			),
			'admin_password' => $this->io->askAndValidate(
				'* Admin password: ',
				self::getValidator( 'required' )
			),
			'admin_email' => $this->io->askAndValidate(
				'* Admin email: ',
				self::getValidator( 'required' )
			),
		);

		return $this->wpCli( 'core install', $inst_args );
	}

	/**
	 * [process description]
	 * @param  [type] $cmd [description]
	 * @return [type]      [description]
	 */
	protected function wpCli( $cmd, $options = array(), $input = null, $throw = true ) {
		$cmd = escapeshellcmd( $cmd );
		$options = self::buildLongOptions( $options );
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => STDOUT,
		);

		$proc = proc_open( "$this->wpCli $cmd $options", $descriptors, $pipes );
		if( !is_resource($proc) ) {
			throw new \RuntimeException( "[wp-cli] Undexpected error. executed command line -> $cmd" );
		}

		if( is_string($input) ) {
			fwrite( $pipes[0], $input );
			fclose( $pipes[0] );
		}

		while( ($output = fgets($pipes[1])) !== false ) {
			$this->io->write( $output, false );
		}
		fclose( $pipes[1] );
		
		$exit = proc_close( $proc );
		if( $throw && $exit !== 0 ) {
			throw new \RuntimeException( "[wp-cli] Command failed. executed command line -> $cmd / options -> $options" );
		}

		return $exit;
	}

	/**
	 * [getValidator description]
	 * @param  [type] $rules [description]
	 * @return [type]        [description]
	 */
	protected static function getValidator( $rules ) {
		return function( $answer ) use( $rules ) {
			$errors = array();

			foreach( (array) $rules as $rule ) {
				switch( $rule ) {
					case 'required':
					if( trim( $answer ) === '' ) {
						$errors[] = 'Empty value is not allowed.';
					}
					break;

					case 'alnumunder':
					if( preg_match( '/[^a-zA-Z0-9_]/', $answer ) ) {
						$errors[] = 'Only alphanumeric and underscore is allowed.';
					}
					break;
				}
			}

			if( !empty($errors) ) {
				throw new \RuntimeException( implode( PHP_EOL, $errors ) );
			}

			return $answer;
		};
	}

	/**
	 * [buildLongOptions description]
	 * @param  array  $args [description]
	 * @return string
	 */
	protected static function buildLongOptions( array $args ) {
		$args_escaped = array();

		foreach( $args as $name => $value ) {
			if( $value === '' )
				continue;

			$arg = "--$name";
			if( $value !== null )
				$arg .= "=$value";

			$args_escaped[] = escapeshellarg( $arg );
		}

		return implode( ' ', $args_escaped );
	}

	/**
	 * [discardProgress description]
	 * @param  [type] $stat [description]
	 * @return [type]       [description]
	 */
	protected function discardProgress( $stat ) {
		switch( $stat ) {
			case 'db':
			$this->pdo->exec( "DROP DATABASE {$this->conf_args['dbname']}" );

			case 'config':
			@unlink( 'wp-config.php' );
			break;
		}
	}
}