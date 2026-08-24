<?php

namespace ContentFactory\Import;

use ContentFactory\Resolve\HierarchyResolver;
use ContentFactory\Service\ContentPipeline;
use ContentFactory\WordPress\DraftManager;
use ContentFactory\Log\OperationLogger;
use ContentFactory\Contract\ValidationIssue;
use ContentFactory\Adapter\AdapterRegistry;

defined( 'ABSPATH' ) || exit;

final class BatchRunner {
	public function __construct(
		private HierarchyResolver $hierarchy,
		private ContentPipeline $pipeline,
		private DraftManager $drafts,
		private ?OperationLogger $logger = null,
		private ?AdapterRegistry $adapters = null
	) {}

	public function validate( array $specs ): array|\WP_Error {
		$sorted = $this->hierarchy->sort_batch( $specs );
		if ( is_wp_error( $sorted ) ) {
			return $sorted;
		}
		$paths = $this->planned_paths( $sorted );
		$urls  = array_map( static fn( string $path ): string => home_url( $path ), $paths );
		$ids   = array_fill_keys( array_keys( $paths ), 0 );
		$results = array();
		foreach ( $specs as $index => $spec ) {
			$report = $this->pipeline->process( $spec, array( 'batch_ids' => $ids, 'batch_paths' => $paths, 'source_urls' => $urls ) );
			$results[] = array( 'index' => $index, 'sourceId' => $spec['sourceId'] ?? '', 'title' => $spec['post']['title'] ?? '', 'spec' => $spec, 'report' => $report );
		}

		$by_source = array();
		foreach ( $results as $index => $result ) {
			$by_source[ $result['sourceId'] ] = $index;
		}
		$path_owners = array();
		foreach ( $paths as $source_id => $path ) {
			if ( isset( $path_owners[ $path ] ) ) {
				foreach ( array( $source_id, $path_owners[ $path ] ) as $duplicate_id ) {
					$results[ $by_source[ $duplicate_id ] ]['report']->add( ValidationIssue::error( 'DUPLICATE_BATCH_PATH', '/post/slug', 'Две страницы batch разрешаются в один hierarchical path.', $duplicate_id, '', 'unique path', 'Измените slug или parent одной из страниц.' ) );
				}
			} else {
				$path_owners[ $path ] = $source_id;
			}
		}

		$dependency_marked = array();
		do {
			$changed = false;
			foreach ( $results as $index => $result ) {
				$source_id = $result['sourceId'];
				$dependencies = $this->link_dependencies( $result['spec'] );
				$parent_source = $result['spec']['post']['parent']['sourceId'] ?? '';
				if ( $parent_source ) {
					$dependencies['sources'][] = $parent_source;
				}
				if ( isset( $dependency_marked[ $source_id ] ) ) {
					continue;
				}
				foreach ( array_unique( $dependencies['sources'] ) as $target_source ) {
					if ( isset( $by_source[ $target_source ] ) && $results[ $by_source[ $target_source ] ]['report']->has_errors() && ! $this->source_exists( $target_source ) ) {
						$code = $target_source === $parent_source ? 'BATCH_PARENT_INCOMPATIBLE' : 'BATCH_LINK_TARGET_INCOMPATIBLE';
						$path = $target_source === $parent_source ? '/post/parent/sourceId' : '/sections';
						$results[ $index ]['report']->add( ValidationIssue::error( $code, $path, 'Зависимая batch-страница несовместима и не будет создана.', $source_id, '', 'compatible link target', 'Исправьте PageSpec целевой страницы.' ) );
						$dependency_marked[ $source_id ] = true;
						$changed = true;
						continue 2;
					}
				}
				foreach ( array_unique( $dependencies['paths'] ) as $target_path ) {
					$owner = array_search( '/' . trim( $target_path, '/' ) . '/', array_map( static fn( string $path ): string => '/' . trim( $path, '/' ) . '/', $paths ), true );
					if ( false !== $owner && isset( $by_source[ $owner ] ) && $results[ $by_source[ $owner ] ]['report']->has_errors() && ! get_page_by_path( trim( $target_path, '/' ), OBJECT, 'page' ) ) {
						$results[ $index ]['report']->add( ValidationIssue::error( 'BATCH_PATH_TARGET_INCOMPATIBLE', '/sections', 'Целевая path-страница batch несовместима и не будет создана.', $source_id, '', 'compatible path target', 'Исправьте PageSpec целевой страницы.' ) );
						$dependency_marked[ $source_id ] = true;
						$changed = true;
						continue 2;
					}
				}
			}
		} while ( $changed );
		return $results;
	}

	public function run( array $specs, bool $confirmed ): array|\WP_Error {
		if ( ! $confirmed ) {
			return new \WP_Error( 'confirmation_required', 'Создание drafts требует явного подтверждения.', array( 'status' => 400 ) );
		}
		$operation_id = null;
		if ( $this->logger ) {
			$adapter = $this->adapters?->active();
			$operation_context = array( 'batch_id' => wp_generate_uuid4() );
			if ( $adapter ) {
				$operation_context += array( 'profile_id' => $adapter->id(), 'profile_version' => $adapter->version(), 'manifest_hash' => $adapter->manifest_hash() );
			}
			$started = $this->logger->start( 'batch_import', $operation_context );
			$operation_id = is_wp_error( $started ) ? null : $started;
		}
		$preview = $this->validate( $specs );
		if ( is_wp_error( $preview ) ) {
			if ( $operation_id ) { $this->logger->finish( $operation_id, 'failed', array( 'total' => count( $specs ), 'failed' => count( $specs ) ) ); }
			return $preview;
		}
		$blocked = array();
		foreach ( $preview as $row ) {
			if ( $row['report']->has_errors() ) {
				$blocked[ $row['sourceId'] ] = $row['report'];
			}
		}
		$hierarchy_sorted = $this->hierarchy->sort_batch( $specs );
		if ( is_wp_error( $hierarchy_sorted ) ) {
			if ( $operation_id ) { $this->logger->finish( $operation_id, 'failed', array( 'total' => count( $specs ), 'failed' => count( $specs ) ) ); }
			return $hierarchy_sorted;
		}
		$all_paths = $this->planned_paths( $hierarchy_sorted );
		// Planned sourceId/path links resolve without a post ID, so runtime order must
		// preserve the hierarchy. A category commonly links to its children while the
		// children point back through post.parent; treating both link directions as
		// ordering edges puts a child before its not-yet-created parent.
		$sorted = $hierarchy_sorted;
		$paths = array_diff_key( $all_paths, $blocked );
		$urls  = array_map( static fn( string $path ): string => home_url( $path ), $paths );
		$spec_by_id = array_column( $specs, null, 'sourceId' );
		$created_ids = array();
		$results = array();
		$failed = array();
		$successful = array();
		foreach ( $sorted as $spec ) {
			$source_id = $spec['sourceId'] ?? '';
			if ( isset( $blocked[ $source_id ] ) ) {
				$results[] = array( 'sourceId' => $source_id, 'action' => 'skipped', 'status' => 'incompatible', 'report' => $blocked[ $source_id ], 'error' => 'PageSpec не прошёл batch validation.' );
				$failed[ $source_id ] = true;
				if ( $operation_id ) { $this->logger->log_page( $operation_id, array( 'sourceId' => $source_id, 'action' => 'skipped', 'result' => 'validation_error', 'compatibility_status' => 'incompatible', 'issues' => $blocked[ $source_id ]->issues() ) ); }
				continue;
			}
			$failed_dependency = '';
			foreach ( $this->direct_dependency_ids( $spec, $paths ) as $dependency_id ) {
				if ( isset( $failed[ $dependency_id ] ) ) {
					$failed_dependency = $dependency_id;
					break;
				}
			}
			if ( $failed_dependency ) {
				$page_result = array( 'sourceId' => $source_id, 'action' => 'skipped', 'status' => 'incompatible', 'error' => 'Невозможно создать страницу: batch dependency ' . $failed_dependency . ' завершилась ошибкой.' );
				$results[] = $page_result;
				if ( $operation_id ) { $this->logger->log_page( $operation_id, array( 'sourceId' => $source_id, 'action' => 'skipped', 'result' => 'dependency_error', 'compatibility_status' => 'incompatible' ) ); }
				$failed[ $source_id ] = true;
				$this->rollback_failed_dependents( $failed, $successful, $results, $spec_by_id, $paths, $created_ids, $operation_id );
				continue;
			}
			$state_before = $this->drafts->capture_state( $source_id );
			$result = $this->drafts->import( $spec, array( 'batch_ids' => $created_ids, 'batch_paths' => $paths, 'source_urls' => $urls ) );
			if ( is_wp_error( $result ) ) {
				$results[] = array( 'sourceId' => $source_id, 'action' => 'error', 'status' => 'incompatible', 'error' => $result->get_error_message(), 'data' => $result->get_error_data() );
				$failed[ $source_id ] = true;
				if ( $operation_id ) { $this->logger->log_page( $operation_id, array_merge( array( 'sourceId' => $source_id, 'action' => 'import', 'result' => 'error', 'compatibility_status' => 'incompatible' ), $this->safe_error_log( $result ) ) ); }
				$this->rollback_failed_dependents( $failed, $successful, $results, $spec_by_id, $paths, $created_ids, $operation_id );
				continue;
			}
			$results[] = $result;
			$successful[ $source_id ] = array( 'state' => $state_before, 'postId' => (int) ( $result['postId'] ?? 0 ), 'resultIndex' => count( $results ) - 1, 'action' => $result['action'] ?? '' );
			if ( $operation_id ) { $this->logger->log_page( $operation_id, array_merge( $result, array( 'result' => 'success', 'compatibility_status' => $result['report']->status(), 'resolved_path' => $paths[ $source_id ] ?? ( $result['resolved_path'] ?? '' ) ) ) ); }
			if ( ! empty( $result['postId'] ) ) {
				$created_ids[ $source_id ] = (int) $result['postId'];
			}
		}
		$counts = $this->counts( $results );
		if ( $operation_id ) { $this->logger->finish( $operation_id, $counts['failed'] ? 'partial' : 'completed', $counts ); }
		return array( 'operationId' => $operation_id, 'counts' => $counts, 'results' => $results );
	}

	private function planned_paths( array $specs ): array {
		$by_id = array();
		foreach ( $specs as $spec ) {
			$by_id[ $spec['sourceId'] ] = $spec;
		}
		$paths = array();
		$path_for = function ( string $id ) use ( &$path_for, &$paths, $by_id ): string {
			if ( isset( $paths[ $id ] ) ) {
				return $paths[ $id ];
			}
			$spec = $by_id[ $id ];
			$slug = trim( $spec['post']['slug'] ?? '', '/' );
			$parent_source = $spec['post']['parent']['sourceId'] ?? '';
			if ( $parent_source && isset( $by_id[ $parent_source ] ) ) {
				return $paths[ $id ] = rtrim( $path_for( $parent_source ), '/' ) . '/' . $slug . '/';
			}
			if ( ! empty( $spec['post']['parent']['path'] ) ) {
				return $paths[ $id ] = '/' . trim( $spec['post']['parent']['path'], '/' ) . '/' . $slug . '/';
			}
			return $paths[ $id ] = '/' . $slug . '/';
		};
		foreach ( array_keys( $by_id ) as $id ) {
			$path_for( $id );
		}
		return $paths;
	}

	private function link_dependencies( array $spec ): array {
		$dependencies = array( 'sources' => array(), 'paths' => array() );
		$walk = function ( mixed $value ) use ( &$walk, &$dependencies ): void {
			if ( ! is_array( $value ) ) {
				return;
			}
			if ( 'page' === ( $value['kind'] ?? null ) && is_string( $value['sourceId'] ?? null ) ) {
				$dependencies['sources'][] = $value['sourceId'];
			}
			if ( 'path' === ( $value['kind'] ?? null ) && is_string( $value['path'] ?? null ) ) {
				$dependencies['paths'][] = $value['path'];
			}
			foreach ( $value as $child ) {
				$walk( $child );
			}
		};
		$walk( $spec['sections'] ?? array() );
		return $dependencies;
	}

	private function direct_dependency_ids( array $spec, array $paths ): array {
		$links = $this->link_dependencies( $spec );
		$dependencies = array_values( array_filter( $links['sources'], static fn( string $source_id ): bool => array_key_exists( $source_id, $paths ) ) );
		$parent_source = $spec['post']['parent']['sourceId'] ?? '';
		if ( $parent_source ) {
			$dependencies[] = $parent_source;
		}
		$normalized_paths = array_map( static fn( string $path ): string => '/' . trim( $path, '/' ) . '/', $paths );
		foreach ( $links['paths'] as $path ) {
			$owner = array_search( '/' . trim( $path, '/' ) . '/', $normalized_paths, true );
			if ( false !== $owner && ! get_page_by_path( trim( $path, '/' ), OBJECT, 'page' ) ) {
				$dependencies[] = $owner;
			}
		}
		return array_values( array_unique( array_filter( $dependencies, static fn( mixed $id ): bool => is_string( $id ) && '' !== $id ) ) );
	}

	private function sort_by_dependencies( array $specs, array $paths ): array {
		$by_id = array();
		foreach ( $specs as $spec ) {
			$by_id[ $spec['sourceId'] ] = $spec;
		}
		$state = array();
		$ordered = array();
		$visit = function ( string $id ) use ( &$visit, &$state, &$ordered, $by_id, $paths ): bool {
			if ( 1 === ( $state[ $id ] ?? 0 ) ) {
				return true;
			}
			if ( 2 === ( $state[ $id ] ?? 0 ) ) {
				return true;
			}
			$state[ $id ] = 1;
			foreach ( $this->direct_dependency_ids( $by_id[ $id ], $paths ) as $dependency ) {
				if ( $dependency === $id || ! isset( $by_id[ $dependency ] ) || $this->source_exists( $dependency ) ) {
					continue;
				}
				$visit( $dependency );
			}
			$state[ $id ] = 2;
			$ordered[] = $by_id[ $id ];
			return true;
		};
		foreach ( array_keys( $by_id ) as $id ) {
			$visit( $id );
		}
		return $ordered;
	}

	private function rollback_failed_dependents( array &$failed, array &$successful, array &$results, array $spec_by_id, array $paths, array &$created_ids, ?string $operation_id ): void {
		do {
			$changed = false;
			foreach ( $successful as $source_id => $state ) {
				$dependencies = $this->direct_dependency_ids( $spec_by_id[ $source_id ], $paths );
				if ( ! array_intersect( $dependencies, array_keys( $failed ) ) ) {
					continue;
				}
				$no_change = 'no_change' === $state['action'];
				$rolled_back = ! $no_change && $this->drafts->rollback_to_state( $state['state'], $state['postId'] );
				if ( $no_change && $state['postId'] ) {
					update_post_meta( $state['postId'], '_content_factory_validation_status', 'stale' );
				}
				if ( ! $rolled_back && $state['postId'] ) {
					update_post_meta( $state['postId'], '_content_factory_validation_status', 'stale' );
				}
				$results[ $state['resultIndex'] ]['action'] = $no_change ? 'invalidated' : 'rolled_back';
				$results[ $state['resultIndex'] ]['status'] = 'incompatible';
				$results[ $state['resultIndex'] ]['error'] = 'Импорт откачен: зависимая batch-страница завершилась ошибкой.';
				$results[ $state['resultIndex'] ]['rollback'] = $rolled_back;
				$failed[ $source_id ] = true;
				unset( $successful[ $source_id ], $created_ids[ $source_id ] );
				if ( $operation_id ) {
					$this->logger->log_page( $operation_id, array( 'sourceId' => $source_id, 'postId' => $state['postId'], 'action' => $no_change ? 'invalidate' : 'rollback', 'result' => $no_change ? 'invalidated' : ( $rolled_back ? 'rolled_back' : 'rollback_failed' ), 'compatibility_status' => 'incompatible', 'rollback_result' => array( 'success' => $rolled_back, 'reason' => 'runtime_dependency_failed' ) ) );
				}
				$changed = true;
			}
		} while ( $changed );
	}

	private function source_exists( string $source_id ): bool {
		$ids = get_posts( array( 'post_type' => 'page', 'post_status' => array( 'draft', 'publish', 'pending', 'private' ), 'meta_key' => '_content_factory_source_id', 'meta_value' => $source_id, 'fields' => 'ids', 'posts_per_page' => 1 ) );
		return ! empty( $ids );
	}

	private function safe_error_log( \WP_Error $error ): array {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		$report = $data['report'] ?? null;
		return array(
			'issues' => $report instanceof \ContentFactory\Contract\CompatibilityReport ? $report->issues() : array(),
			'migrations' => $report instanceof \ContentFactory\Contract\CompatibilityReport ? ( $report->context()['migrations'] ?? array() ) : array(),
			'defaults_applied' => $report instanceof \ContentFactory\Contract\CompatibilityReport ? ( $report->context()['defaultsApplied'] ?? array() ) : array(),
			'rollback_result' => array( 'rollback' => true === ( $data['rollback'] ?? false ), 'status' => absint( $data['status'] ?? 0 ), 'errorCode' => $error->get_error_code() ),
		);
	}

	private function counts( array $results ): array {
		$counts = array( 'total' => count( $results ), 'created' => 0, 'updated' => 0, 'no_change' => 0, 'failed' => 0 );
		foreach ( $results as $result ) {
			$action = $result['action'] ?? 'error';
			if ( isset( $counts[ $action ] ) ) {
				++$counts[ $action ];
			} else {
				++$counts['failed'];
			}
		}
		return $counts;
	}
}
