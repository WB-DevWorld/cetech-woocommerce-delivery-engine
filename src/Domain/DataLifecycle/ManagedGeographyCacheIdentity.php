<?php

declare(strict_types=1);

namespace CetechDeliveryEngine\Domain\DataLifecycle;

/** Server-resolved private cache identity; no query facts are serializable. */
final readonly class ManagedGeographyCacheIdentity implements \JsonSerializable {
	public const OPTION_PREFIX = 'cetech_de_gc_geo_v1_';
	public const CACHE_GROUP = 'cetech_de_geography_managed_v1';
	private function __construct( private int $site, private string $kind_value, private string $digest_value, private string $revision_value ) {}
	public static function create( int $site_id, string $kind, string $country, string $parent, string $query, string $extra, int $page, string $revision, string $locale, string $salt ): self {
		if ( $site_id < 1 || $page < 1 || ! in_array( $kind, [ 'children', 'search', 'postcode' ], true ) || '' === $salt || strlen( $salt ) > 4096 ) { self::invalid(); }
		foreach ( [ $country, $parent, $query, $extra, $revision, $locale ] as $text ) { if ( strlen( $text ) > 8192 || 1 !== preg_match( '//u', $text ) || 1 === preg_match( '/[\x00-\x1f\x7f]/', $text ) ) { self::invalid(); } }
		if ( strlen( $country ) > 2 || strlen( $parent ) > 256 || strlen( $revision ) > 256 || '' === $locale || strlen( $locale ) > 128 ) { self::invalid(); }
		$tuple = [ 'purpose' => 'geography_response_cache_v1', 'format' => 1, 'site_id' => $site_id, 'kind' => $kind, 'country' => strtoupper( $country ), 'parent' => $parent, 'query' => $query, 'extra' => $extra, 'page' => $page, 'revision' => $revision, 'locale' => $locale ];
		return new self( $site_id, $kind, hash_hmac( 'sha256', json_encode( $tuple, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $salt ), $revision );
	}
	public function site_id(): int { return $this->site; }
	public function kind(): string { return $this->kind_value; }
	public function digest(): string { return $this->digest_value; }
	public function option_name(): string { return self::OPTION_PREFIX . $this->digest_value; }
	public function revision(): string { return $this->revision_value; }
	public function jsonSerialize(): never { throw new \LogicException( 'Managed cache identity requires an explicit projection.' ); }
	private static function invalid(): never { throw new \InvalidArgumentException( 'Invalid managed geography cache identity.' ); }
}
