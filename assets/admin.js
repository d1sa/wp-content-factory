( function () {
	'use strict';

	const root = document.getElementById( 'content-factory-admin' );
	if ( ! root || ! window.wp || ! wp.apiFetch ) {
		return;
	}

	const config = window.contentFactoryAdmin || {};
	if ( config.nonce && wp.apiFetch.createNonceMiddleware ) {
		wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( config.nonce ) );
	}

	const state = {
		report: null,
		validatedFile: null,
		packageHash: '',
		validatedPageCount: 0,
		canImport: false,
		managedLoaded: false,
	};

	const elements = {
		importForm: document.getElementById( 'cf-import-form' ),
		importFile: document.getElementById( 'cf-import-file' ),
		validateButton: document.getElementById( 'cf-validate-button' ),
		importSpinner: document.getElementById( 'cf-import-spinner' ),
		importStatus: document.getElementById( 'cf-import-status' ),
		uploadLimit: document.getElementById( 'cf-upload-limit' ),
		preview: document.getElementById( 'cf-preview' ),
		previewSummary: document.getElementById( 'cf-preview-summary' ),
		previewTable: document.getElementById( 'cf-preview-table' ),
		downloadReport: document.getElementById( 'cf-download-report' ),
		createCompatible: document.getElementById( 'cf-create-compatible' ),
		managedStatus: document.getElementById( 'cf-managed-status' ),
		managedTable: document.getElementById( 'cf-managed-table' ),
		refreshManaged: document.getElementById( 'cf-refresh-managed' ),
		publishControls: document.getElementById( 'cf-publish-controls' ),
		reviewConfirmed: document.getElementById( 'cf-review-confirmed' ),
		publishSelected: document.getElementById( 'cf-publish-selected' ),
	};

	function element( tag, className, text ) {
		const node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function formatBytes( bytes ) {
		const value = Number( bytes );
		if ( ! Number.isFinite( value ) || value <= 0 ) {
			return '';
		}
		if ( value >= 1024 * 1024 ) {
			return ( value / ( 1024 * 1024 ) ).toLocaleString( 'ru-RU', { maximumFractionDigits: 1 } ) + ' МБ';
		}
		return Math.ceil( value / 1024 ).toLocaleString( 'ru-RU' ) + ' КБ';
	}

	function errorMessage( error ) {
		if ( error && error.message ) {
			return error.message;
		}
		return 'Не удалось выполнить запрос. Повторите попытку.';
	}

	function showNotice( container, message, type ) {
		container.replaceChildren();
		if ( ! message ) {
			return;
		}
		const notice = element( 'div', 'notice notice-' + ( type || 'info' ) + ' inline' );
		if ( 'error' === type ) {
			notice.setAttribute( 'role', 'alert' );
		}
		notice.appendChild( element( 'p', '', message ) );
		container.appendChild( notice );
	}

	function setBusy( button, spinner, busy ) {
		button.disabled = busy;
		button.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		if ( spinner ) {
			spinner.classList.toggle( 'is-active', busy );
		}
	}

	function activateTab( tab, updateUrl ) {
		const activeTab = 'managed' === tab ? 'managed' : 'import';
		root.querySelectorAll( '[data-cf-tab]' ).forEach( function ( link ) {
			const active = link.dataset.cfTab === activeTab;
			link.classList.toggle( 'nav-tab-active', active );
			link.setAttribute( 'aria-current', active ? 'page' : 'false' );
		} );
		root.querySelectorAll( '[data-cf-panel]' ).forEach( function ( panel ) {
			panel.hidden = panel.dataset.cfPanel !== activeTab;
		} );

		if ( updateUrl && window.history && window.history.replaceState ) {
			const url = new URL( window.location.href );
			url.searchParams.set( 'tab', activeTab );
			window.history.replaceState( {}, '', url.toString() );
		}

		if ( 'managed' === activeTab && ! state.managedLoaded ) {
			loadManagedPages();
		}
	}

	function validationPages( report, fallbackFilename ) {
		let pages;
		if ( Array.isArray( report ) ) {
			pages = report;
		} else if ( report && Array.isArray( report.pages ) ) {
			pages = report.pages;
		} else if ( report && Array.isArray( report.results ) ) {
			pages = report.results;
		} else if ( report && Array.isArray( report.items ) ) {
			pages = report.items;
		} else {
			pages = report ? [ report ] : [];
		}

		return pages.map( function ( item, index ) {
			const result = item || {};
			const reportData = result.report || result.result || result;
			const spec = reportData.normalizedSpec || result.normalizedSpec || result.spec || reportData.spec || result.pageSpec || null;
			const issues = Array.isArray( reportData.issues ) ? reportData.issues : [];
			const hasErrors = issues.some( function ( issue ) {
				return issue && 'error' === issue.severity;
			} );
			return {
				filename: result.filename || ( report && Array.isArray( report.files ) && report.files[ index ] ) || fallbackFilename || 'Файл ' + ( index + 1 ),
				status: reportData.status || result.status || ( hasErrors ? 'incompatible' : 'compatible' ),
				issues: issues,
				spec: spec,
				sourceId: result.sourceId || ( spec && spec.sourceId ) || '',
				title: result.title || ( spec && spec.post && spec.post.title ) || ( spec && spec.title ) || '',
				path: result.expectedPath || result.path || ( reportData.resolved && reportData.resolved.expectedPath ) || '',
				plannedAction: result.plannedAction || 'conflict',
				counts: reportData.counts || result.counts || {},
			};
		} );
	}

	function isCompatible( page ) {
		if ( 'incompatible' === page.status || 'error' === page.status ) {
			return false;
		}
		return ! page.issues.some( function ( issue ) {
			return issue && 'error' === issue.severity;
		} );
	}

	function countFor( page, key ) {
		const direct = Number( page.counts[ key ] );
		if ( Number.isFinite( direct ) ) {
			return direct;
		}
		if ( 'sections' === key && page.spec && Array.isArray( page.spec.sections ) ) {
			return page.spec.sections.length;
		}
		if ( page.spec && ( 'links' === key || 'assets' === key ) ) {
			return countDescriptors( page.spec.sections || [], key );
		}
		return 0;
	}

	function countDescriptors( value, type ) {
		if ( ! value || 'object' !== typeof value ) {
			return 0;
		}
		if ( ! Array.isArray( value ) ) {
			const keys = Object.keys( value );
			const isDescriptor = 'links' === type
				? [ 'anchor', 'page', 'path', 'external', 'tel', 'mailto' ].includes( value.kind )
				: [ 'mediaId', 'mediaUrl', 'themeAsset', 'externalUrl' ].includes( value.source );
			if ( isDescriptor ) {
				return 1;
			}
		}
		return Object.keys( value ).reduce( function ( total, key ) {
			return total + countDescriptors( value[ key ], type );
		}, 0 );
	}

	function statusLabel( status ) {
		const labels = {
			compatible: 'Совместима',
			compatible_with_warnings: 'С предупреждениями',
			incompatible: 'Требует исправления',
			valid: 'Проверена',
			draft: 'Черновик',
			publish: 'Опубликована',
			create: 'Будет создана',
			update_draft: 'Черновик обновится',
			no_change: 'Без изменений',
			blocked_published: 'Опубликована — заблокировано',
			conflict: 'Конфликт',
		};
		return labels[ status ] || status || 'Неизвестно';
	}

	function statusBadge( status ) {
		const badge = element( 'span', 'cf-badge cf-badge--' + String( status || 'unknown' ).replace( /[^a-z0-9_-]/gi, '' ), statusLabel( status ) );
		return badge;
	}

	function issuesList( issues ) {
		if ( ! issues.length ) {
			return element( 'span', 'description', 'Нет' );
		}
		const list = element( 'ul', 'cf-issues' );
		issues.forEach( function ( issue ) {
			const severity = issue.severity || 'info';
			const item = element( 'li', 'cf-issue cf-issue--' + severity );
			item.appendChild( element( 'span', 'cf-issue__message', issue.message || 'Без описания' ) );
			const technical = element( 'details', 'cf-issue__technical' );
			technical.appendChild( element( 'summary', '', 'Технические детали' ) );
			technical.appendChild( element( 'code', '', [ severity.toUpperCase(), issue.code, issue.path ].filter( Boolean ).join( ' · ' ) ) );
			item.appendChild( technical );
			list.appendChild( item );
		} );
		return list;
	}

	function renderPreview( report, filename, file ) {
		const pages = validationPages( report, filename );
		state.report = report;
		state.validatedFile = file;
		state.packageHash = report && report.packageHash ? String( report.packageHash ) : '';
		state.validatedPageCount = pages.length;

		const compatibleCount = pages.filter( isCompatible ).length;
		const warningCount = pages.filter( function ( page ) {
			return 'compatible_with_warnings' === page.status;
		} ).length;
		const incompatibleCount = pages.length - compatibleCount;
		state.canImport = pages.length > 0 && 0 === incompatibleCount && !! state.validatedFile && !! state.packageHash;
		elements.previewSummary.textContent = 'Всего: ' + pages.length + '. Совместимы: ' + compatibleCount + '. С предупреждениями: ' + warningCount + '. Требуют исправления: ' + incompatibleCount + '.';

		const table = element( 'table', 'widefat striped cf-report-table' );
		const head = element( 'thead' );
		const headRow = element( 'tr' );
		[ 'Файл / страница', 'Путь', 'Статус', 'Действие', 'Секции', 'Ссылки', 'Assets', 'Issues' ].forEach( function ( heading ) {
			const cell = element( 'th', '', heading );
			cell.scope = 'col';
			headRow.appendChild( cell );
		} );
		head.appendChild( headRow );
		table.appendChild( head );
		const body = element( 'tbody' );

		pages.forEach( function ( page ) {
			const row = element( 'tr' );
			const identity = element( 'td' );
			identity.appendChild( element( 'strong', '', page.title || page.sourceId || page.filename ) );
			identity.appendChild( element( 'div', 'description', page.sourceId || page.filename ) );
			row.appendChild( identity );
			row.appendChild( element( 'td', '', page.path || '—' ) );
			const statusCell = element( 'td' );
			statusCell.appendChild( statusBadge( page.status ) );
			row.appendChild( statusCell );
			const actionCell = element( 'td' );
			actionCell.appendChild( statusBadge( page.plannedAction ) );
			row.appendChild( actionCell );
			row.appendChild( element( 'td', '', String( countFor( page, 'sections' ) ) ) );
			row.appendChild( element( 'td', '', String( countFor( page, 'links' ) ) ) );
			row.appendChild( element( 'td', '', String( countFor( page, 'assets' ) ) ) );
			const issueCell = element( 'td' );
			issueCell.appendChild( issuesList( page.issues ) );
			row.appendChild( issueCell );
			body.appendChild( row );
		} );

		table.appendChild( body );
		elements.previewTable.replaceChildren( table );
		elements.preview.hidden = false;
		elements.createCompatible.disabled = ! state.canImport;
		elements.createCompatible.textContent = 'Создать или обновить черновики: ' + pages.length;

		if ( incompatibleCount ) {
			showNotice( elements.importStatus, 'Пакет содержит ошибки. В атомарном режиме черновики не будут созданы, пока ошибки не исправлены.', 'warning' );
		} else {
			showNotice( elements.importStatus, 'Проверка завершена. Просмотрите отчёт перед созданием черновиков.', 'success' );
		}
	}

	async function validateUpload( event ) {
		event.preventDefault();
		const file = elements.importFile.files[0];
		if ( ! file ) {
			showNotice( elements.importStatus, 'Выберите JSON- или ZIP-файл.', 'error' );
			return;
		}
		if ( Number( config.maxUpload ) > 0 && file.size > Number( config.maxUpload ) ) {
			showNotice( elements.importStatus, 'Файл превышает допустимый размер ' + formatBytes( config.maxUpload ) + '.', 'error' );
			return;
		}

		const formData = new FormData();
		formData.append( 'file', file, file.name );
		setBusy( elements.validateButton, elements.importSpinner, true );
		elements.preview.hidden = true;
		state.report = null;
		state.validatedFile = null;
		state.packageHash = '';
		state.canImport = false;
		showNotice( elements.importStatus, 'Файл загружается и проверяется…', 'info' );

		try {
			const report = await wp.apiFetch( {
				path: '/content-factory/v1/validate?detail=summary',
				method: 'POST',
				body: formData,
			} );
			renderPreview( report, file.name, file );
		} catch ( error ) {
			showNotice( elements.importStatus, errorMessage( error ), 'error' );
		} finally {
			setBusy( elements.validateButton, elements.importSpinner, false );
		}
	}

	async function downloadReport() {
		if ( ! state.validatedFile ) {
			return;
		}
		setBusy( elements.downloadReport, null, true );
		showNotice( elements.importStatus, 'Готовим полный технический отчёт…', 'info' );
		try {
			const formData = new FormData();
			formData.append( 'file', state.validatedFile, state.validatedFile.name );
			const report = await wp.apiFetch( {
				path: '/content-factory/v1/validate?detail=full',
				method: 'POST',
				body: formData,
			} );
			const blob = new Blob( [ JSON.stringify( report, null, 2 ) ], { type: 'application/json;charset=utf-8' } );
			const url = URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = 'content-factory-report.json';
			document.body.appendChild( link );
			link.click();
			link.remove();
			URL.revokeObjectURL( url );
			showNotice( elements.importStatus, 'Полный технический отчёт скачан.', 'success' );
		} catch ( error ) {
			showNotice( elements.importStatus, errorMessage( error ), 'error' );
		} finally {
			setBusy( elements.downloadReport, null, false );
		}
	}

	async function createCompatiblePages() {
		if ( ! state.canImport || ! state.validatedFile ) {
			return;
		}
		setBusy( elements.createCompatible, null, true );
		showNotice( elements.importStatus, 'Атомарно создаём или обновляем черновики из проверенного пакета…', 'info' );
		try {
			const formData = new FormData();
			formData.append( 'file', state.validatedFile, state.validatedFile.name );
			formData.append( 'confirmed', 'true' );
			formData.append( 'detail', 'summary' );
			formData.append( 'validatedHash', state.packageHash );
			const result = await wp.apiFetch( {
				path: '/content-factory/v1/pages/batch',
				method: 'POST',
				body: formData,
			} );
			const failed = result && result.counts ? Number( result.counts.failed || 0 ) : 0;
			if ( failed > 0 ) {
				showNotice( elements.importStatus, 'Атомарный импорт остановлен. Пакет не изменил черновики; ошибок: ' + failed + '.', 'error' );
				return;
			}
			const count = result && ( result.createdCount || result.created || ( result.counts && ( result.counts.created + result.counts.updated + result.counts.no_change ) ) || ( result.results && result.results.length ) );
			showNotice( elements.importStatus, count ? 'Операция завершена. Обработано черновиков: ' + count + '.' : 'Пакет обработан без изменений.', 'success' );
			state.managedLoaded = false;
		} catch ( error ) {
			showNotice( elements.importStatus, errorMessage( error ), 'error' );
		} finally {
			setBusy( elements.createCompatible, null, false );
			elements.createCompatible.disabled = ! state.canImport;
		}
	}

	function managedPages( response ) {
		if ( Array.isArray( response ) ) {
			return response;
		}
		if ( response && Array.isArray( response.pages ) ) {
			return response.pages;
		}
		if ( response && Array.isArray( response.items ) ) {
			return response.items;
		}
		if ( response && Array.isArray( response.results ) ) {
			return response.results;
		}
		return [];
	}

	function safeLink( href, label, className ) {
		if ( ! href ) {
			return null;
		}
		try {
			const url = new URL( href, window.location.origin );
			if ( ! [ 'http:', 'https:' ].includes( url.protocol ) ) {
				return null;
			}
			const link = element( 'a', className || '', label );
			link.href = url.toString();
			return link;
		} catch ( error ) {
			return null;
		}
	}

	function managedValue( page, camel, snake, fallback ) {
		if ( page[ camel ] !== undefined && page[ camel ] !== null ) {
			return page[ camel ];
		}
		if ( page[ snake ] !== undefined && page[ snake ] !== null ) {
			return page[ snake ];
		}
		return fallback;
	}

	function selectedSourceIds() {
		return Array.from( elements.managedTable.querySelectorAll( 'tbody input[data-source-id]:checked' ) ).map( function ( input ) {
			return input.dataset.sourceId;
		} );
	}

	function selectedPageSummaries() {
		return Array.from( elements.managedTable.querySelectorAll( 'tbody input[data-source-id]:checked' ) ).map( function ( input ) {
			const cells = input.closest( 'tr' ).cells;
			return {
				sourceId: input.dataset.sourceId,
				title: cells[ 1 ] ? cells[ 1 ].innerText.trim() : input.dataset.sourceId,
				warnings: cells[ 8 ] ? Number( cells[ 8 ].innerText.trim() ) || 0 : 0,
			};
		} );
	}

	function updatePublishControls() {
		const count = selectedSourceIds().length;
		elements.publishControls.hidden = 0 === count;
		elements.publishSelected.textContent = 'Опубликовать выбранные: ' + count;
		elements.publishSelected.disabled = 0 === count || ! elements.reviewConfirmed.checked;
		if ( 0 === count ) {
			elements.reviewConfirmed.checked = false;
		}
	}

	function renderManagedPages( pages ) {
		if ( ! pages.length ) {
			elements.managedTable.replaceChildren( element( 'p', 'cf-empty-state', 'Управляемых страниц пока нет.' ) );
			elements.publishControls.hidden = true;
			return;
		}

		const table = element( 'table', 'wp-list-table widefat fixed striped table-view-list pages cf-managed-table' );
		const head = element( 'thead' );
		const headRow = element( 'tr' );
		const selectHead = element( 'td', 'manage-column column-cb check-column' );
		const selectAll = document.createElement( 'input' );
		selectAll.type = 'checkbox';
		selectAll.id = 'cf-select-all-pages';
		selectAll.setAttribute( 'aria-label', 'Выбрать все управляемые страницы' );
		selectHead.appendChild( selectAll );
		headRow.appendChild( selectHead );
		[ 'Заголовок', 'sourceId', 'Путь', 'Статус', 'Проверка', 'Последний импорт', 'Последняя проверка', 'Warnings', 'Действия' ].forEach( function ( heading ) {
			const cell = element( 'th', 'manage-column', heading );
			cell.scope = 'col';
			headRow.appendChild( cell );
		} );
		head.appendChild( headRow );
		table.appendChild( head );

		const body = element( 'tbody' );
		pages.forEach( function ( page, index ) {
			const sourceId = String( managedValue( page, 'sourceId', 'source_id', '' ) );
			const title = String( managedValue( page, 'title', 'post_title', sourceId || 'Без заголовка' ) );
			const row = element( 'tr' );
			const selectCell = element( 'th', 'check-column' );
			selectCell.scope = 'row';
			const checkbox = document.createElement( 'input' );
			checkbox.type = 'checkbox';
			checkbox.dataset.sourceId = sourceId;
			checkbox.id = 'cf-page-' + index;
			checkbox.disabled = ! sourceId
				|| 'draft' !== String( managedValue( page, 'status', 'post_status', '' ) )
				|| 'valid' !== String( managedValue( page, 'validationStatus', 'validation_status', '' ) );
			checkbox.setAttribute( 'aria-label', 'Выбрать страницу «' + title + '»' );
			selectCell.appendChild( checkbox );
			row.appendChild( selectCell );

			const titleCell = element( 'td', 'column-primary' );
			const editLink = safeLink( managedValue( page, 'editLink', 'edit_link', '' ), title, 'row-title' );
			titleCell.appendChild( editLink || element( 'strong', '', title ) );
			row.appendChild( titleCell );
			row.appendChild( element( 'td', 'cf-code', sourceId || '—' ) );
			row.appendChild( element( 'td', '', String( managedValue( page, 'path', 'path', '—' ) ) ) );

			const statusCell = element( 'td' );
			statusCell.appendChild( statusBadge( String( managedValue( page, 'status', 'post_status', '' ) ) ) );
			row.appendChild( statusCell );
			const validationCell = element( 'td' );
			validationCell.appendChild( statusBadge( String( managedValue( page, 'validationStatus', 'validation_status', '' ) ) ) );
			row.appendChild( validationCell );
			row.appendChild( element( 'td', '', String( managedValue( page, 'lastImport', 'last_import', '—' ) ) ) );
			row.appendChild( element( 'td', '', String( managedValue( page, 'lastValidation', 'last_validation', '—' ) ) ) );
			row.appendChild( element( 'td', '', String( managedValue( page, 'warningsCount', 'warnings_count', 0 ) ) ) );

			const actions = element( 'td', 'cf-row-actions' );
			const previewLink = safeLink( managedValue( page, 'previewLink', 'preview_link', '' ), 'Просмотреть' );
			if ( previewLink ) {
				previewLink.target = '_blank';
				previewLink.rel = 'noopener noreferrer';
				actions.appendChild( previewLink );
			}
			const revalidate = element( 'button', 'button button-small', 'Проверить снова' );
			revalidate.type = 'button';
			revalidate.dataset.revalidate = sourceId;
			revalidate.disabled = ! sourceId;
			actions.appendChild( revalidate );
			row.appendChild( actions );
			body.appendChild( row );
		} );
		table.appendChild( body );
		elements.managedTable.replaceChildren( table );

		selectAll.addEventListener( 'change', function () {
			elements.managedTable.querySelectorAll( 'tbody input[data-source-id]:not(:disabled)' ).forEach( function ( checkbox ) {
				checkbox.checked = selectAll.checked;
			} );
			updatePublishControls();
		} );
		body.addEventListener( 'change', updatePublishControls );
		body.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '[data-revalidate]' );
			if ( button ) {
				revalidatePage( button.dataset.revalidate, button );
			}
		} );
		updatePublishControls();
	}

	async function loadManagedPages() {
		state.managedLoaded = true;
		setBusy( elements.refreshManaged, null, true );
		showNotice( elements.managedStatus, 'Загружаем управляемые страницы…', 'info' );
		elements.managedTable.replaceChildren();
		try {
			const response = await wp.apiFetch( { path: '/content-factory/v1/pages', method: 'GET' } );
			const pages = managedPages( response );
			renderManagedPages( pages );
			showNotice( elements.managedStatus, pages.length ? 'Страницы загружены: ' + pages.length + '.' : '', 'success' );
		} catch ( error ) {
			state.managedLoaded = false;
			elements.managedTable.replaceChildren( element( 'p', 'cf-empty-state', 'Не удалось загрузить список.' ) );
			showNotice( elements.managedStatus, errorMessage( error ), 'error' );
		} finally {
			setBusy( elements.refreshManaged, null, false );
		}
	}

	async function revalidatePage( sourceId, button ) {
		if ( ! sourceId ) {
			return;
		}
		setBusy( button, null, true );
		showNotice( elements.managedStatus, 'Повторно проверяем «' + sourceId + '»…', 'info' );
		try {
			await wp.apiFetch( {
				path: '/content-factory/v1/pages/' + encodeURIComponent( sourceId ) + '/revalidate',
				method: 'POST',
			} );
			showNotice( elements.managedStatus, 'Страница «' + sourceId + '» проверена.', 'success' );
			await loadManagedPages();
		} catch ( error ) {
			showNotice( elements.managedStatus, errorMessage( error ), 'error' );
		} finally {
			setBusy( button, null, false );
		}
	}

	async function publishSelected() {
		const sourceIds = selectedSourceIds();
		const summaries = selectedPageSummaries();
		if ( ! sourceIds.length ) {
			showNotice( elements.managedStatus, 'Выберите хотя бы одну страницу.', 'error' );
			return;
		}
		if ( ! elements.reviewConfirmed.checked ) {
			showNotice( elements.managedStatus, 'Подтвердите, что выбранные страницы проверены.', 'error' );
			return;
		}
		const confirmation = 'Финальная проверка и публикация:\n\n' + summaries.map( function ( page ) {
			return '- ' + page.title + ' [' + page.sourceId + ']' + ( page.warnings ? ', warnings: ' + page.warnings : '' );
		} ).join( '\n' );
		if ( ! window.confirm( confirmation ) ) {
			return;
		}

		setBusy( elements.publishSelected, null, true );
		showNotice( elements.managedStatus, 'Выполняется финальная проверка и публикация…', 'info' );
		try {
			const result = await wp.apiFetch( {
				path: '/content-factory/v1/pages/publish-selected',
				method: 'POST',
				data: { sourceIds: sourceIds, confirmed: true },
			} );
			const published = Array.isArray( result ) ? result.filter( function ( row ) {
				return row && 'published' === row.status;
			} ).length : result && ( result.publishedCount || result.published || ( result.results && result.results.length ) );
			const rows = Array.isArray( result ) ? result : ( result && result.results ) || [];
			const details = rows.map( function ( row ) {
				return ( row.sourceId || 'без sourceId' ) + ': ' + ( 'published' === row.status ? 'опубликована' : 'ошибка: ' + ( row.message || row.status || 'неизвестно' ) );
			} ).join( '\n' );
			showNotice( elements.managedStatus, ( published ? 'Опубликовано страниц: ' + published + '.' : 'Публикация не выполнена.' ) + ( details ? '\n' + details : '' ), rows.some( function ( row ) { return 'published' !== row.status; } ) ? 'warning' : 'success' );
			elements.reviewConfirmed.checked = false;
			await loadManagedPages();
		} catch ( error ) {
			showNotice( elements.managedStatus, errorMessage( error ), 'error' );
		} finally {
			setBusy( elements.publishSelected, null, false );
			updatePublishControls();
		}
	}

	root.querySelectorAll( '[data-cf-tab]' ).forEach( function ( link ) {
		link.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			activateTab( link.dataset.cfTab, true );
		} );
	} );
	elements.importForm.addEventListener( 'submit', validateUpload );
	elements.importFile.addEventListener( 'change', function () {
		state.report = null;
		state.validatedFile = null;
		state.packageHash = '';
		state.validatedPageCount = 0;
		state.canImport = false;
		elements.preview.hidden = true;
		elements.createCompatible.disabled = true;
	} );
	elements.downloadReport.addEventListener( 'click', downloadReport );
	elements.createCompatible.addEventListener( 'click', createCompatiblePages );
	elements.refreshManaged.addEventListener( 'click', loadManagedPages );
	elements.reviewConfirmed.addEventListener( 'change', updatePublishControls );
	elements.publishSelected.addEventListener( 'click', publishSelected );

	if ( Number( config.maxUpload ) > 0 ) {
		elements.uploadLimit.textContent = 'Максимальный размер загрузки: ' + formatBytes( config.maxUpload ) + '.';
	}
	activateTab( config.initialTab || 'import', false );
}() );
