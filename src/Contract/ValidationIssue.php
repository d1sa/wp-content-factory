<?php

namespace ContentFactory\Contract;

defined( 'ABSPATH' ) || exit;

final class ValidationIssue implements \JsonSerializable {
	public function __construct(
		private string $code,
		private string $severity,
		private string $path,
		private string $message,
		private string $source_id = '',
		private string $section_id = '',
		private string $expected = '',
		private string $suggestion = '',
		private string $docs_ref = ''
	) {}

	public static function error( string $code, string $path, string $message, string $source_id = '', string $section_id = '', string $expected = '', string $suggestion = '', string $docs_ref = '' ): self {
		return new self( $code, 'error', $path, $message, $source_id, $section_id, $expected, $suggestion, $docs_ref );
	}

	public static function warning( string $code, string $path, string $message, string $source_id = '', string $section_id = '', string $expected = '', string $suggestion = '', string $docs_ref = '' ): self {
		return new self( $code, 'warning', $path, $message, $source_id, $section_id, $expected, $suggestion, $docs_ref );
	}

	public static function info( string $code, string $path, string $message, string $source_id = '', string $section_id = '', string $expected = '', string $suggestion = '', string $docs_ref = '' ): self {
		return new self( $code, 'info', $path, $message, $source_id, $section_id, $expected, $suggestion, $docs_ref );
	}

	public function severity(): string {
		return $this->severity;
	}

	public function jsonSerialize(): array {
		$data = array(
			'code'       => $this->code,
			'severity'   => $this->severity,
			'path'       => $this->path,
			'sourceId'   => $this->source_id,
			'message'    => $this->message,
			'expected'   => $this->expected,
			'suggestion' => $this->suggestion,
			'docsRef'    => $this->docs_ref,
		);
		if ( '' !== $this->section_id ) {
			$data['sectionId'] = $this->section_id;
		}
		return $data;
	}
}

