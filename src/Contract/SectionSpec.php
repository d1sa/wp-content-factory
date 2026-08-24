<?php

namespace ContentFactory\Contract;

defined( 'ABSPATH' ) || exit;

final class SectionSpec implements \JsonSerializable {
	public function __construct( private string $id, private string $type, private array $data ) {}

	public static function from_array( array $section ): self {
		return new self( (string) ( $section['id'] ?? '' ), (string) ( $section['type'] ?? '' ), is_array( $section['data'] ?? null ) ? $section['data'] : array() );
	}

	public function id(): string { return $this->id; }
	public function type(): string { return $this->type; }
	public function data(): array { return $this->data; }

	public function jsonSerialize(): array {
		return array( 'id' => $this->id, 'type' => $this->type, 'data' => $this->data );
	}
}

