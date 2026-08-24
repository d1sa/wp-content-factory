<?php

namespace ContentFactory\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminPage {
	public const CAPABILITY = 'content_factory_import_pages';
	public const MENU_SLUG  = 'content-factory';

	private string $hook_suffix = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'Content Factory', 'content-factory' ),
			__( 'Content Factory', 'content-factory' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-media-document',
			58
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( $this->hook_suffix !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'content-factory-admin',
			CONTENT_FACTORY_URL . 'assets/admin.css',
			array(),
			CONTENT_FACTORY_VERSION
		);

		wp_enqueue_script(
			'content-factory-admin',
			CONTENT_FACTORY_URL . 'assets/admin.js',
			array( 'wp-api-fetch' ),
			CONTENT_FACTORY_VERSION,
			true
		);

		wp_localize_script(
			'content-factory-admin',
			'contentFactoryAdmin',
			array(
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'maxUpload'  => wp_max_upload_size(),
				'initialTab' => $this->current_tab(),
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'У вас нет прав для доступа к Content Factory.', 'content-factory' ) );
		}

		$current_tab = $this->current_tab();
		$import_url  = add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => 'import',
			),
			admin_url( 'admin.php' )
		);
		$managed_url = add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => 'managed',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="wrap content-factory-admin" id="content-factory-admin">
			<h1><?php echo esc_html__( 'Content Factory', 'content-factory' ); ?></h1>

			<nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__( 'Разделы Content Factory', 'content-factory' ); ?>">
				<a
					class="nav-tab<?php echo 'import' === $current_tab ? ' nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( $import_url ); ?>"
					data-cf-tab="import"
					aria-current="<?php echo 'import' === $current_tab ? 'page' : 'false'; ?>"
				>
					<?php echo esc_html__( 'Импорт', 'content-factory' ); ?>
				</a>
				<a
					class="nav-tab<?php echo 'managed' === $current_tab ? ' nav-tab-active' : ''; ?>"
					href="<?php echo esc_url( $managed_url ); ?>"
					data-cf-tab="managed"
					aria-current="<?php echo 'managed' === $current_tab ? 'page' : 'false'; ?>"
				>
					<?php echo esc_html__( 'Управляемые страницы', 'content-factory' ); ?>
				</a>
			</nav>

			<section class="cf-panel" data-cf-panel="import" <?php echo 'import' !== $current_tab ? 'hidden' : ''; ?>>
				<h2><?php echo esc_html__( 'Пакет статей', 'content-factory' ); ?></h2>
				<p class="description">
					<?php echo esc_html__( 'Загрузите ZIP, подготовленный Content Factory или Codex. Сначала пакет будет проверен без создания страниц.', 'content-factory' ); ?>
				</p>
				<p class="description">
					<?php echo esc_html__( 'В текущем профиле нет верхнего лимита у article, catalog, steps и faq, а также у карточек, шагов и вопросов FAQ. Технические ограничения файла и ZIP-пакета сохраняются.', 'content-factory' ); ?>
				</p>
				<details class="cf-advanced-import">
					<summary><?php echo esc_html__( 'Расширенный импорт', 'content-factory' ); ?></summary>
					<p class="description"><?php echo esc_html__( 'Для разработки также принимается один PageSpec JSON или JSON envelope. Обычный импорт пакета всегда выполняется атомарно.', 'content-factory' ); ?></p>
				</details>

				<form id="cf-import-form" enctype="multipart/form-data">
					<label for="cf-import-file" class="screen-reader-text">
						<?php echo esc_html__( 'Пакет статей для проверки', 'content-factory' ); ?>
					</label>
					<input id="cf-import-file" name="file" type="file" accept=".json,.zip,application/json,application/zip" required>
					<button class="button button-primary" type="submit" id="cf-validate-button">
						<?php echo esc_html__( 'Загрузить и проверить', 'content-factory' ); ?>
					</button>
					<span class="spinner" id="cf-import-spinner" aria-hidden="true"></span>
				</form>
				<p class="description" id="cf-upload-limit"></p>
				<div id="cf-import-status" class="cf-status-region" role="status" aria-live="polite"></div>

				<div id="cf-preview" class="cf-preview" hidden>
					<div class="cf-section-heading">
						<h2><?php echo esc_html__( 'Отчёт о совместимости', 'content-factory' ); ?></h2>
						<div class="cf-actions">
							<button type="button" class="button" id="cf-download-report">
								<?php echo esc_html__( 'Скачать JSON-отчёт', 'content-factory' ); ?>
							</button>
							<button type="button" class="button button-primary" id="cf-create-compatible" disabled>
								<?php echo esc_html__( 'Создать или обновить черновики', 'content-factory' ); ?>
							</button>
						</div>
					</div>
					<div id="cf-preview-summary" class="cf-summary" aria-live="polite"></div>
					<div id="cf-preview-table" class="cf-table-wrap"></div>
				</div>
			</section>

			<section class="cf-panel" data-cf-panel="managed" <?php echo 'managed' !== $current_tab ? 'hidden' : ''; ?>>
				<div class="cf-section-heading">
					<h2><?php echo esc_html__( 'Управляемые страницы', 'content-factory' ); ?></h2>
					<button type="button" class="button" id="cf-refresh-managed">
						<?php echo esc_html__( 'Обновить', 'content-factory' ); ?>
					</button>
				</div>
				<div id="cf-managed-status" class="cf-status-region" role="status" aria-live="polite"></div>
				<div id="cf-managed-table" class="cf-table-wrap"></div>

				<div class="cf-publish-controls" id="cf-publish-controls" hidden>
					<label for="cf-review-confirmed">
						<input type="checkbox" id="cf-review-confirmed">
						<?php echo esc_html__( 'Я проверил выбранные страницы', 'content-factory' ); ?>
					</label>
					<button type="button" class="button button-primary" id="cf-publish-selected" disabled>
						<?php echo esc_html__( 'Опубликовать выбранные', 'content-factory' ); ?>
					</button>
				</div>
			</section>
		</div>
		<?php
	}

	private function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'import';
		return in_array( $tab, array( 'import', 'managed' ), true ) ? $tab : 'import';
	}
}
