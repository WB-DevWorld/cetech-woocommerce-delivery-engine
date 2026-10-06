<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Infrastructure\WordPress;

use CetechDeliveryEngine\Domain\Operation\OperationSession;
use CetechDeliveryEngine\Domain\Operation\OperationConnectionFactory as OperationFactory;

/** Authoritative server configuration; never accepts request-supplied routing. */
final class OperationConnectionFactory implements OperationFactory {

	private ?array $configuration = null;
	private int $site = 0;
	private string $prefix = '';

	public function __construct( private readonly ?OperationFactory $equivalent_factory = null ) {
	}

	/** @param array<string, mixed> $configuration Trusted disposable/server settings only. */
	public static function from_server_configuration( int $site_id, string $prefix, array $configuration ): self {
		$factory = new self();
		$factory->site = $site_id;
		$factory->prefix = $prefix;
		$factory->configuration = $configuration;
		return $factory;
	}

	public function open(): OperationSession {
		try {
			return $this->open_configured();
		} catch ( \Throwable ) {
			throw new \RuntimeException( 'Operation connection is unavailable.' );
		}
	}

	private function open_configured(): OperationSession {
		if ( null !== $this->equivalent_factory ) {
			return $this->equivalent_factory->open();
		}
		$config = $this->configuration;
		$site = $this->site;
		$prefix = $this->prefix;
		if ( null === $config ) {
			global $wpdb;
			if ( ! defined( 'DB_HOST' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! is_object( $wpdb ) || \wpdb::class !== get_class( $wpdb ) || ( defined( 'WP_CONTENT_DIR' ) && is_file( WP_CONTENT_DIR . '/db.php' ) ) || ! method_exists( $wpdb, 'parse_db_host' ) ) {
				throw new \RuntimeException( 'Operation connection is unavailable.' );
			}
			$host = $wpdb->parse_db_host( DB_HOST );
			if ( ! is_array( $host ) || count( $host ) < 3 ) {
				throw new \RuntimeException( 'Operation connection is unavailable.' );
			}
			$config = [
				'host'      => $host[0],
				'port'      => $host[1] ?: 3306,
				'socket'    => $host[2],
				'user'      => DB_USER,
				'password'  => DB_PASSWORD,
				'database'  => DB_NAME,
				'charset'   => $wpdb->charset ?: 'utf8mb4',
				'collation' => $wpdb->collate ?: '',
				'flags'     => defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0,
			];
			$site = function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1;
			$prefix = $wpdb->prefix;
		}
		$transport = null;
		try {
			$charset = (string) ( $config['charset'] ?? 'utf8mb4' );
			$collation = (string) ( $config['collation'] ?? '' );
			$transport = OperationConnectionMysqliTransport::connect(
				(string) ( $config['host'] ?? '' ),
				(string) ( $config['user'] ?? '' ),
				(string) ( $config['password'] ?? '' ),
				(string) ( $config['database'] ?? '' ),
				(int) ( $config['port'] ?? 3306 ),
				isset( $config['socket'] ) ? (string) $config['socket'] : null,
				$charset,
				(int) ( $config['flags'] ?? 0 )
			);
			return new OperationConnection( $site, $prefix, $transport, $charset, $collation );
		} catch ( \Throwable ) {
			if ( $transport instanceof OperationConnectionTransport ) {
				$transport->close();
			}
			throw new \RuntimeException( 'Operation connection is unavailable.' );
		}
	}
}
