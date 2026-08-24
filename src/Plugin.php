<?php

namespace ContentFactory;

use ContentFactory\Adapter\AdapterRegistry;
use ContentFactory\Adapter\PotolkiInnerAdapter;
use ContentFactory\Admin\AdminPage;
use ContentFactory\Build\GutenbergSerializer;
use ContentFactory\Import\BatchRunner;
use ContentFactory\Import\JsonImporter;
use ContentFactory\Import\ZipImporter;
use ContentFactory\Log\OperationLogger;
use ContentFactory\Resolve\HierarchyResolver;
use ContentFactory\Rest\RestController;
use ContentFactory\Service\ContentPipeline;
use ContentFactory\Validation\CorePageSpecValidator;
use ContentFactory\WordPress\DraftManager;
use ContentFactory\WordPress\HashManager;
use ContentFactory\WordPress\PublishGuard;
use ContentFactory\WordPress\PublishManager;
use ContentFactory\WordPress\YoastAdapter;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static ?self $instance = null;
	private bool $booted = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public static function activate(): void {
		OperationLogger::install();
		foreach ( array( 'administrator' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( 'content_factory_import_pages' );
				$role->add_cap( 'content_factory_publish_pages' );
			}
		}
		add_option( 'content_factory_publish_policy', 'manager_only', '', false );
		if ( ! wp_next_scheduled( 'content_factory_cleanup_logs' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'content_factory_cleanup_logs' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'content_factory_cleanup_logs' );
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
		$adapters = new AdapterRegistry();
		$adapters->register( new PotolkiInnerAdapter() );
		do_action( 'content_factory_register_adapters', $adapters );

		$hierarchy = new HierarchyResolver();
		$serializer = new GutenbergSerializer();
		$pipeline = new ContentPipeline( $adapters, new CorePageSpecValidator(), $hierarchy, $serializer );
		$logger = new OperationLogger();
		$drafts = new DraftManager( $pipeline, $adapters, new HashManager(), new YoastAdapter(), $logger );
		$batch = new BatchRunner( $hierarchy, $pipeline, $drafts, $logger, $adapters );
		$publisher = new PublishManager( $pipeline, $adapters, new HashManager() );
		$rest = new RestController( $adapters, $pipeline, $drafts, $batch, $publisher, new JsonImporter(), new ZipImporter(), $logger );

		add_action( 'rest_api_init', array( $rest, 'register' ) );
		( new PublishGuard() )->register();
		add_action( 'content_factory_cleanup_logs', static fn() => $logger->cleanup() );

		if ( is_admin() && class_exists( AdminPage::class ) ) {
			$admin = new AdminPage();
			$admin->register();
		}
	}
}
