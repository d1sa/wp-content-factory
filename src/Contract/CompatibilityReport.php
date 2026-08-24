<?php

namespace ContentFactory\Contract;

defined( 'ABSPATH' ) || exit;

final class CompatibilityReport implements \JsonSerializable {
	/** @var ValidationIssue[] */
	private array $issues = array();
	private array $context = array();

	public function add( ValidationIssue $issue ): self {
		$this->issues[] = $issue;
		return $this;
	}

	public function merge( self $other ): self {
		foreach ( $other->issues() as $issue ) {
			$this->add( $issue );
		}
		return $this;
	}

	/** @return ValidationIssue[] */
	public function issues(): array {
		return $this->issues;
	}

	public function has_errors(): bool {
		foreach ( $this->issues as $issue ) {
			if ( 'error' === $issue->severity() ) {
				return true;
			}
		}
		return false;
	}

	public function status(): string {
		if ( $this->has_errors() ) {
			return 'incompatible';
		}
		foreach ( $this->issues as $issue ) {
			if ( 'warning' === $issue->severity() ) {
				return 'compatible_with_warnings';
			}
		}
		return 'compatible';
	}

	public function set_context( string $key, mixed $value ): self {
		$this->context[ $key ] = $value;
		return $this;
	}

	public function context(): array {
		return $this->context;
	}

	public function jsonSerialize(): array {
		return array_merge(
			array(
				'status' => $this->status(),
				'issues' => $this->issues,
			),
			$this->context
		);
	}
}

