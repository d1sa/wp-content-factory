<?php

namespace ContentFactory\Contract;

defined( 'ABSPATH' ) || exit;

final class PageSpec implements \JsonSerializable {
	/** @var SectionSpec[] */
	private array $sections;

	private function __construct( private array $raw ) {
		$this->sections = array_map( static fn( array $section ): SectionSpec => SectionSpec::from_array( $section ), $raw['sections'] ?? array() );
	}

	public static function from_array( array $spec ): self { return new self( $spec ); }
	public function source_id(): string { return (string) ( $this->raw['sourceId'] ?? '' ); }
	public function page_type(): string { return (string) ( $this->raw['pageType'] ?? '' ); }
	public function post(): array { return $this->raw['post'] ?? array(); }
	public function seo(): array { return $this->raw['seo'] ?? array(); }
	/** @return SectionSpec[] */
	public function sections(): array { return $this->sections; }
	public function jsonSerialize(): array { return $this->raw; }
}

