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
use ContentFactory\WordPress\ManagedPageObserver;
use ContentFactory\WordPress\PublishManager;
use ContentFactory\WordPress\YoastAdapter;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private const ADMINISTRATOR_CAPABILITIES = array(
		'content_factory_import_pages',
		'content_factory_publish_pages',
	);

	private static ?self $instance = null;
	private bool $booted = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public static function activate(): void {
		OperationLogger::install();
		self::ensure_administrator_capabilities();
		if ( ! wp_next_scheduled( 'content_factory_cleanup_logs' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'content_factory_cleanup_logs' );
		}
	}

	/** Restore required capabilities if a WordPress role update removed them. */
	public static function ensure_administrator_capabilities(): void {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}

		foreach ( self::ADMINISTRATOR_CAPABILITIES as $capability ) {
			if ( ! $role->has_cap( $capability ) ) {
				$role->add_cap( $capability );
			}
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
		self::ensure_administrator_capabilities();
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
		( new ManagedPageObserver() )->register();
		add_action( 'content_factory_cleanup_logs', static fn() => $logger->cleanup() );

		if ( is_admin() && class_exists( AdminPage::class ) ) {
			$admin = new AdminPage();
			$admin->register();
		}
	}
}
